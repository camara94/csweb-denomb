<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AppBundle\CSPro;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Psr\Log\LoggerInterface;
use AppBundle\CSPro\Dictionary\MySQLDictionarySchemaGenerator;
use AppBundle\CSPro\DictionaryHelper;
use Doctrine\DBAL\Schema;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\Data\MySQLQuestionnaireSerializer;

/**
 * Description of DictionarySchemaHelper
 *
 * @author savy
 */
class DictionarySchemaHelper {

    const JOB_STATUS_NOT_STARTED = '0';
    const JOB_STATUS_IN_PROCESS = '1';
    const JOB_STATUS_COMPLETE = '2';

    private $logger;
    private $pdo;
    private $dictionaryName;
    private $conn;
    private $config;
    private $dictionary;
    private $connectionParams;
    private $initialized;

    public function __construct(string $dictionaryName, PdoHelper $pdo, LoggerInterface $commandLogger) {
        $this->logger = $commandLogger;
        $this->pdo = $pdo;
        $this->dictionaryName = $dictionaryName;
        $this->initialized = false;
        $this->dictionary = null;
        $this->connectionParams = null;
        $this->conn = null;
        $this->config = null;
    }

    private function getConnectionParameters(): bool {
        $stm = "SELECT host_name, schema_name, schema_user_name, AES_DECRYPT(schema_password, '" . "cspro') as `password` FROM `cspro_dictionaries_schema` JOIN `cspro_dictionaries` ON dictionary_id = cspro_dictionaries.id WHERE cspro_dictionaries.dictionary_name = :dictName";
        $bind = array('dictName' => $this->dictionaryName);

        $result = $this->pdo->fetchOne($stm, $bind);

        if ($result) {
            $this->connectionParams = array(
                'dbname' => $result['schema_name'],
                'user' => $result['schema_user_name'],
                'password' => $result['password'],
                'host' => $result['host_name'],
                'driver' => 'pdo_mysql',
            );
            return true;
        } else {
            $this->connectionParams = null;
            $this->logger->error('Database information not found for dictionary: ' . $this->dictionaryName);
            return false;
        }
    }

    public function initialize($checkDictionarySchema = false): bool {
//get the connection parameters
        /* Provide DBAL with some initial database infor */
        if ($this->initialized == true) { //allow init to be done only once to prevent gc
            return $this->initialized;
        }
        $this->config = new Configuration();
        try {
//load dictionary
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);
            $this->dictionary = $dictionaryHelper->loadDictionary($this->dictionaryName);

            /* Connect to the database */
            $this->initialized = $this->getConnectionParameters();
            if ($this->initialized == false) {
                return $this->initialized;
            }
            $this->conn = DriverManager::getConnection($this->connectionParams, $this->config);
            if ($checkDictionarySchema && !$this->IsValidSchema()) { //thread never should call using checkDictionarySchema as true
//drop all the tables that exist. 
                $this->cleanDictionarySchema();
                $this->createDictionarySchema();
            }
        } catch (\Exception $e) {
            $strMsg = "Failed initializing database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
        $this->initialized = true;
        return $this->initialized;
    }

    private function cleanDictionarySchema() {
        try {
            $tables = $this->conn->getSchemaManager()->listTables();
            if (count($tables) > 0) {
                $this->conn->prepare("SET FOREIGN_KEY_CHECKS = 0;")->execute();

                foreach ($tables as $table) {
                    $sql = 'DROP TABLE ' . MySQLDictionarySchemaGenerator::quoteString($table->getName());
                    $this->conn->prepare($sql)->execute();
                }
                $this->conn->prepare("SET FOREIGN_KEY_CHECKS = 1;")->execute();
            }
        } catch (\Exception $e) {
            $strMsg = "Failed deleting tables from database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
    }

    private function createDictionarySchema() {

        try {
            $dictionarySchema = new MySQLDictionarySchemaGenerator($this->logger);
            $schema = $dictionarySchema->generateDictionary($this->dictionary);
            $dictionarySQL = $schema->toSql($this->conn->getDatabasePlatform());
            $dictionarySQL = implode(";" . PHP_EOL, $dictionarySQL);
            $this->logger->debug("writing schema SQL " . $dictionarySQL);

            $this->conn->prepare($dictionarySQL)->execute();

            //insert into cspro_meta dictionary information
            $dictionaryVersion = $this->dictionary->getVersion();
            $stm = "SELECT modified_time, `dictionary_full_content` FROM `cspro_dictionaries` "
                    . " WHERE  `dictionary_name` = '" . $this->dictionaryName . "'";
            $result = $this->pdo->fetchOne($stm);
            if ($result) {
                $stm = "INSERT INTO `cspro_meta`(`cspro_version`, `dictionary`, `source_modified_time`) "
                        . "VALUES (:version, :dictionary, :source_modified_time)";
                $bind['version'] = $dictionaryVersion;
                $bind['dictionary'] = $result['dictionary_full_content'];
                $bind['source_modified_time'] = $result['modified_time'];
                $stmt = $this->conn->executeUpdate($stm, $bind);
            }
        } catch (\Exception $e) {
            $strMsg = "Failed generating tables in database: " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
    }

    public function tableExists($table) {
        try {
            $result = $this->conn->executeQuery("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception $e) {
            return false;
        }
        // ALW - By default PDO will not throw exceptions, so check result also.
        return $result !== false;
    }

    public function IsValidSchema(): bool {
        //check the time stamp of dictionary in the meta table with the original dictionary timestamp.
        $isValid = false;
        try {
            if (!$this->tableExists("`cspro_meta`")) {
                return $isValid;
            }
            $stm = "SELECT source_modified_time FROM `cspro_meta` ";
            $stmt = $this->conn->executeQuery($stm);
            $result = $stmt->fetch();
            if ($result) {
                $stm = "SELECT count(*) FROM `cspro_dictionaries` "
                        . " WHERE  `dictionary_name` = :dictionaryName and `modified_time` = :source_modified_time";
                $bind['dictionaryName'] = $this->dictionaryName;
                $bind['source_modified_time'] = $result['source_modified_time'];

                $result = (int) $this->pdo->fetchValue($stm, $bind);
                $isValid = ($result === 1) ? true : false;
            }
        } catch (\Exception $e) {
            $strMsg = "Failed validating schema  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
        $this->logger->debug('The schema valid flag is ' . $isValid);
        return $isValid;
    }

    //reset in process jobs to not started at the start of the long process to be picked up again
    public function resetInProcesssJobs(): int {
        try {
            $stm = "UPDATE `cspro_jobs` SET `status`= :status WHERE `status` = :in_process_jobs";
            $bind['status'] = self::JOB_STATUS_NOT_STARTED;
            $bind['in_process_jobs'] = self::JOB_STATUS_IN_PROCESS;
            $count = $this->conn->executeUpdate($stm, $bind);
        } catch (\Exception $e) {
            $strMsg = "Failed resetting jobs in schema  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
        return $count;
    }

    public function processNextJob($maxCasesPerChunk): int {
        $jobId = 0;
//find a job that is not being processed and update its status to processing 
        try {
            $stm = "SELECT `id` FROM `cspro_jobs` "
                    . " WHERE  `status` = " . self::JOB_STATUS_NOT_STARTED . " ORDER BY `id`  LIMIT 1 ";
            $stmt = $this->conn->prepare($stm);
            $stmt->execute();
            $result = $stmt->fetchAll();
            $jobId = count($result) > 0 ? $result[0]['id'] : $this->createJob($maxCasesPerChunk);
            if ($jobId) {
                $stm = "UPDATE `cspro_jobs` SET `status`= :status WHERE `id` = :id";
                $bind['status'] = self::JOB_STATUS_IN_PROCESS;
                $bind['id'] = $jobId;
                $this->conn->executeUpdate($stm, $bind);
            }
        } catch (\Exception $e) {
            $strMsg = "Failed getting next job from database:  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
        return $jobId;
    }

    public function createJob($maxCasesPerChunk): int {
//if a job already exists - get the endCaseId and endRevision if there are no cases at this revision 
//SELECT the most recent job and get the endCaseId and endRevision 
        $jobId = 0;
        $stm = "SELECT `id`, `start_caseid`, `start_revision`, `end_caseid`, `end_revision`, `cases_processed`, `status` FROM `cspro_jobs` "
                . "ORDER BY `id` DESC LIMIT 1 ";

        try {
            $stmt = $this->conn->prepare($stm);
            $stmt->execute();
            $result = $stmt->fetchAll();
            $endRevision = 0;
            $endCaseId = 0;
            if ($result) {
                $endRevision = $result[0]['end_revision'];
                $endCaseId = $result[0]['end_caseid'];
            }
//select cases from the source cases  table  where revision = end_revision  end_revision id > end_caseid 
            $stm = "SELECT `id`, `revision` FROM " . $this->dictionaryName . " WHERE revision = :endRevision and `id` > :endCaseId  "
                    . " UNION "
                    . "SELECT `id`, `revision` FROM " . $this->dictionaryName . " WHERE revision > :endRevision "
                    . "ORDER BY `revision`, `id` LIMIT :limit";

            $limit = $maxCasesPerChunk;
            $stmt = $this->pdo->prepare($stm);
            $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindParam(':endCaseId', $endCaseId, \PDO::PARAM_INT);
            $stmt->bindParam(':endRevision', $endRevision, \PDO::PARAM_INT);

            $stmt->execute();
            $result = $stmt->fetchAll();
            if ($result) {
                unset($bind);
                $bind['startCaseId'] = $result[0]['id'];
                $bind['startRevision'] = $result[0]['revision'];
                $bind['endCaseId'] = $result[count($result) - 1]['id'];
                $bind['endRevision'] = $result[count($result) - 1]['revision'];
                $bind['cases_to_process'] = count($result);
                $stm = "INSERT INTO `cspro_jobs`(`start_caseid`, `start_revision`, `end_caseid`, `end_revision` ,`cases_to_process`) "
                        . "VALUES (:startCaseId, :startRevision, :endCaseId, :endRevision, :cases_to_process)";
                $stmt = $this->conn->executeUpdate($stm, $bind);
                $jobId = $this->conn->lastInsertId();
            }
            return $jobId;
        } catch (\Exception $e) {
            $strMsg = "Failed creating job in database:  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw $e;
        }
    }

    public function blobBreakOut($jobId) {
        //$dictionary, PdoHelper $sourceDB, $targetDB, $jobID 
        //select cases from sourceDB and generate insertSQL to insert/update the case 
        try {
            $questionnaireSerializer = new MySQLQuestionnaireSerializer($this->dictionary, $jobId, $this->pdo, $this->conn, $this->logger);
            $questionnaireSerializer->serializeQuestionnaries();
        } catch (\Exception $e) {
            $strMsg = "Failed processing questionnaires for JobId: " . $jobId . " in database:  " . $this->connectionParams['dbname'] . " while processsing Dictionary: " . $this->dictionaryName;
            $this->logger->error($strMsg, array("context" => (string) $e));
            throw new \Exception($strMsg, 0, $e);
        }
    }

}
