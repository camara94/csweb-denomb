<?php

namespace AppBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use AppBundle\Service\PdoHelper;
use Psr\Log\LoggerInterface;
use AppBundle\CSPro\Dictionary\MySQLDictionarySchemaGenerator;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\DictionarySchemaHelper;
use Doctrine\DBAL\Schema;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use AppBundle\CSPro\DBConfigSettings;

/**
 * Description of CSWebProcessRunner - based on https://stackoverflow.com/questions/54127418/backend-multi-threading-in-php-7-symfony4
 *
 * @author savy
 */
class CSWebProcessRunner extends Command {

    use LockableTrait;

    const MAX_TIME_LIMIT = 240; //seconds 

    private $startTime;
    private $kernel;
    private $logger;
    private $phpBinaryPath;
    private $pdo;
    private $dictionaryMap;
    private $maxCasesPerChunk;
    private $output;

    //TODO: eventually use  DBAL instead of PDO for all the service operations.
    public function __construct(PdoHelper $pdo, KernelInterface $kernel, LoggerInterface $commandLogger) {
        parent::__construct();
        $this->kernel = $kernel;
        $this->logger = $commandLogger;
        $this->pdo = $pdo;
        $this->dictionaryMap = array();
    }

    protected function configure() {
        //configuration is set to running max three threads per dictionary with each thread processing a a max of 500 cases
        $this
                ->setName('csweb:process-cases')
                ->setDescription('CSWeb blob breakout processing into multiple threads')
                ->addOption('threads', 't', InputOption::VALUE_REQUIRED, 'Number of threads to run at once per dictionary', 3)
                ->addOption('maxCasesPerChunk', 'c', InputOption::VALUE_REQUIRED, 'Number of cases to process per chunk', 500);
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            $this->logger->info('The command is already running in another process.');
            return 0;
        }
        $this->logger->info('Started process at ' . date("c"));
        $this->startTime = microtime(true);
        $this->output = $output;
        $this->maxCasesPerChunk = $input->getOption('maxCasesPerChunk');
        $threadsPerDictionary = $input->getOption('threads');
        $output->writeln('Running blob breakout process.');
        if (extension_loaded('pcntl')) {
            $stop = function () {
                $this->logger->error('Abort process issued');
                $output->writeln('Abort process issued.');
                $output->writeln('Stopping blob breakout process.');
                throw new RuntimeException('Abort process issued');
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
            pcntl_async_signals(true);
        }
        //generate schema for each dictionary that is to be processed if it does not exists 
        $this->createDictionarySchemas();
        $stopProcess = $this->hasProcessTimeExpired();
        do {
            try {
                //set stop process flag if process time expires and no threads are running currently
                $stopProcess = count($this->dictionaryMap) == 0 || ($this->hasProcessTimeExpired() && $this->canExitProcess());
                foreach (array_keys($this->dictionaryMap) as $dictionaryName) {

                    $dictionaryInfo = &$this->dictionaryMap[$dictionaryName];

                    if ($dictionaryInfo->processFlag == false && count($dictionaryInfo->processes) == 0) {
                        //no jobs available and no running threads for this dictionary. Remove dictionary from processing
                        unset($this->dictionaryMap[$dictionaryName]);
                        $this->logger->info("No jobs available to process for dictionary: " . $dictionaryName);
                    }
                    //create new threads if duration is within process expiry time.
                    while (count($dictionaryInfo->processes) < $threadsPerDictionary && !$this->hasProcessTimeExpired() && $dictionaryInfo->processFlag) {
                        $output->writeln('Processing dictionary: ' . $dictionaryName . '- Running threads ' . count($dictionaryInfo->processes));
                        $this->logger->debug('CSWeb Process Runner creating a new blob breakout thread');
                        $output->writeln('creating a new blob breakout thread');
                        $process = $this->createProcess($dictionaryName);
                        if ($process) {
                            $process->setTimeout(self::MAX_TIME_LIMIT);
                            $process->setIdleTimeout(self::MAX_TIME_LIMIT);
                            $process->start();
                            $dictionaryInfo->processes[] = $process;
                        }
                    }

                    //filters array and returns running processes for the current dictionary
                    $dictionaryInfo->processes = array_filter($dictionaryInfo->processes, function (Process $p) {
                        return $p->isRunning();
                    });
                }
                //For use to debug
                /* for ($j = 0; $j < count($dictionaryInfo->processes); $j++) {
                  $dictionaryInfo->processes[$j]->wait(function ($type, $buffer) {
                  echo 'OUT > ' . $type;
                  if (Process::ERR === $type) {
                  echo 'ERR > ' . $buffer;
                  } else {
                  echo 'OUT > ' . $buffer;
                  }
                  });
                  $this->output->writeln("removing process count is " . count($dictionaryInfo->processes));
                  array_splice($dictionaryInfo->processes, $j, 1);
                  $this->output->writeln("after removal process count is " . count($dictionaryInfo->processes));
                  } */
                sleep(1);
            } catch (RuntimeException $e) {
                try {
                    $this->output->writeln("killing process");
                    defined('SIGKILL') || define('SIGKILL', 9);
                    //kill the running threads
                    foreach (array_keys($this->dictionaryMap) as $dictionaryName) {
                        $dictionaryInfo = &$this->dictionaryMap[$dictionaryName];
                            array_map(function (Process $p) {
                                $p->signal(SIGKILL);
                            }, $dictionaryInfo->processes);
                    }
                } catch (\Throwable $e) {
                    
                }
                break;
            }
        } while (!$stopProcess);
        $this->release();
        $this->logger->info('Stopping process at ' . date("c"));
        return 0;
    }

    private function createProcess($dictName) {
        if (!isset($this->dictionaryMap[$dictName])) {
            $this->logger->error("Invalid dictionary Map. Dictionary Information not set for dictionary " . $dictName);
            return null;
        }

        $dictionaryInfo = &$this->dictionaryMap[$dictName];
        $dictionarySchemaHelper = $dictionaryInfo->schemaHelper;
        $jobId = $dictionarySchemaHelper->processNextJob($this->maxCasesPerChunk);
        $this->output->writeln('Creating process for dictionary ' . $dictName . ' jobID: ' . $jobId);
        $this->logger->debug('Creating process for dictionary ' . $dictName . ' jobID: ' . $jobId);
        if (!$this->phpBinaryPath) {
            $this->phpBinaryPath = (new PhpExecutableFinder())->find();
        }
        if ($jobId) {
            $cmd = [
                $this->phpBinaryPath,
                '-f',
                realpath($this->kernel->getProjectDir() . '/bin/console'),
                '--',
                'csweb:blob-breakout-worker',
                '-e',
                $this->kernel->getEnvironment(),
                '-d',
                $dictName,
                '-j',
                $jobId,
            ];
            $this->output->writeln('Processing for dictionary ' . $dictName . ' jobID: ' . $jobId);
            $this->logger->debug('Processing for dictionary ' . $dictName . ' jobID: ' . $jobId);

            return new Process($cmd);
        }
        $this->output->writeln('No jobs available to run for dictionary: ' . $dictName);
        //set process flag to false to stop creating threads for this dictionary
        $dictionaryInfo->processFlag = false;
        return null;
    }

    private function createDictionarySchema($processCasesOptions) {

        $bind = [];
        try {
            $dictionarySchema = new MySQLDictionarySchemaGenerator($this->logger);
            $processCasesOptions = $this->getProcessCaseOptions();
            $schema = $dictionarySchema->generateDictionary($this->dictionary, $processCasesOptions);
            $dictionarySQL = $schema->toSql($this->conn->getDatabasePlatform());
            $explodedDictionarySQL = implode(";" . PHP_EOL, $dictionarySQL);
            $this->logger->debug("writing schema SQL " . $explodedDictionarySQL);

            foreach($dictionarySQL AS $oneDictionarySQL){
                $this->conn->prepare($oneDictionarySQL)->execute();
            }

            //insert into cspro_meta dictionary information
            $dictionaryVersion = $this->dictionary->getVersion();

            //Récupération du label du dictionnaire concerné par les insertions dans la table labeldictionnaire_cspro_meta
            $dictionaryLabel = str_replace(" ", "_", str_replace("_DICT", "", $this->dictionary->getName()));

            $stm = "SELECT modified_time, `dictionary_full_content` FROM `cspro_dictionaries` "
                    . " WHERE  `dictionary_name` = '" . $this->dictionaryName . "'";
            $result = $this->pdo->fetchOne($stm);
            if ($result) {
                $stm = "INSERT INTO ".strtolower($dictionaryLabel)."_cspro_meta(cspro_version, dictionary, source_modified_time) "
                . "VALUES (:version, :dictionary, :source_modified_time)";
                $bind['version'] = $dictionaryVersion;
                $bind['dictionary'] = $result['dictionary_full_content'];
                $bind['source_modified_time'] = $result['modified_time'];
                $stmt = $this->conn->executeUpdate($stm, $bind);
            }
        } catch (\Exception $e) {
            $strMsg = "Failed generating tables in database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, ["context" => (string) $e]);
            throw $e;
        }
    }


    private function canExitProcess(): bool {
        $flag = true;
        foreach (array_keys($this->dictionaryMap) as $dictionaryName) {
            $dictionaryInfo = $this->dictionaryMap[$dictionaryName];
            $processes = $dictionaryInfo->processes;
            if (isset($processes) && count($processes) > 0) { //if threads are running return false;
                $flag = false;
                break;
            }
        }
        return $flag;
    }

    private function hasProcessTimeExpired() {
        $duration = round((microtime(true) - $this->startTime));
        return $duration > self::MAX_TIME_LIMIT;
    }

}
