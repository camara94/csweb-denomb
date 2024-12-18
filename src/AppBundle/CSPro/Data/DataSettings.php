<?php

namespace AppBundle\CSPro\Data;

use AppBundle\Service\PdoHelper;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Schema;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DBALException;

class DataSettings {

    private $logger;
    private $pdo;

    public function __construct(PdoHelper $pdo, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
    }

    public function getDataSettings() {
        $dataSettings = $this->pdo->query('SELECT `cspro_dictionaries`.`id` as id, `dictionary_name`as name, dictionary_label as label,  `host_name` as targetHostName, `schema_name` as targetSchemaName,'
                        . ' `schema_user_name` as dbUserName, AES_DECRYPT(`schema_password`, \'cspro\') as dbPassword, `map_info` as mapInfo FROM `cspro_dictionaries_schema` RIGHT JOIN cspro_dictionaries'
                        . '  ON dictionary_id = cspro_dictionaries.id    ORDER BY dictionary_label')->fetchAll();
        $this->getDataCounts($dataSettings);

        //clear password field
        foreach ($dataSettings as &$dataSetting) {
            $dataSetting['dbPassword'] = "";
        }
        return $dataSettings;
    }

    public function getDataSetting($dictionaryName, $clearPassWord) {
        $dataSetting = null;
        try {
            $stm = 'SELECT `cspro_dictionaries`.`id` as id, `dictionary_name`as name, dictionary_label as label,  `host_name` as targetHostName, `schema_name` as targetSchemaName,'
                    . ' `schema_user_name` as dbUserName, AES_DECRYPT(`schema_password`, \'cspro\') as dbPassword, `map_info` as mapInfo FROM `cspro_dictionaries_schema` RIGHT JOIN cspro_dictionaries'
                    . '  ON dictionary_id = cspro_dictionaries.id  WHERE dictionary_name = :dictName';

            $bind['dictName'] = $dictionaryName;
            $dataSetting = $this->pdo->fetchOne($stm, $bind);
            //clear password field
            if ($clearPassWord && isset($dataSetting)) {
                $dataSetting['dbPassword'] = "";
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting data settings: ' . $dictionaryName, array("context" => (string) $e));
            throw $e;
        }
        return $dataSetting;
    }

    public function addDataSetting($dataSetting): bool {
        $sourceDBName = $this->pdo->query('select database()')->fetchColumn();
        $dataSetting['targetSchemaName'] = trim($dataSetting['targetSchemaName']);
        $dataSetting['dbPassword'] = trim($dataSetting['dbPassword']);
        if (strcasecmp($sourceDBName, $dataSetting['targetSchemaName']) == 0) {
            throw new \Exception("Source database: $sourceDBName cannot be same as  Target database: " . $dataSetting['targetSchemaName']);
        }
        $connectionParams = array(
            'dbname' => $dataSetting['targetSchemaName'],
            'user' => $dataSetting['dbUserName'],
            'password' => $dataSetting['dbPassword'],
            'host' => $dataSetting['targetHostName'],
            'driver' => 'pdo_mysql'
        );
        $config = new Configuration();
        try {
            $conn = DriverManager::getConnection($connectionParams, $config);
            $isConnected = $conn->connect();
//if connection successful add
            if ($isConnected) {
                $stm = "INSERT INTO `cspro_dictionaries_schema`(`dictionary_id`, `host_name`, `schema_name`, `schema_user_name`, `schema_password`, `map_info`) "
                        . "VALUES (:id, :targetHostName, :targetSchemaName, :dbUserName,AES_ENCRYPT(:dbPassword, :keyString), :mapInfo)";

                $bind['id'] = $dataSetting['id'];
                $bind['targetHostName'] = $dataSetting['targetHostName'];
                $bind['targetSchemaName'] = $dataSetting['targetSchemaName'];
                $bind['dbUserName'] = $dataSetting['dbUserName'];
                $bind['dbPassword'] = $dataSetting['dbPassword'];
                $bind['mapInfo'] = json_encode($dataSetting['mapInfo']);
                $bind['keyString'] = 'cspro';
                $stmt = $this->pdo->prepare($stm);
                $stmt->execute($bind);
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed adding configuration: " . $e->getMessage());
            throw $e;
        }
        $flag = isset($conn) ? true : false;
        return $flag;
    }

    public function updateDataSetting($dataSetting): bool {
        $sourceDBName = $this->pdo->query('select database()')->fetchColumn();
        $dataSetting['targetSchemaName'] = trim($dataSetting['targetSchemaName']);
        $dataSetting['dbPassword'] = trim($dataSetting['dbPassword']);
        if (strcasecmp($sourceDBName, $dataSetting['targetSchemaName']) == 0) {
            throw new \Exception("Source database: $sourceDBName cannot be same as  Target database: " . $dataSetting['targetSchemaName']);
        }
        $this->logger->debug('setting is ' . print_r($dataSetting,true));
        $connectionParams = array(
            'dbname' => $dataSetting['targetSchemaName'],
            'user' => $dataSetting['dbUserName'],
            'password' => $dataSetting['dbPassword'],
            'host' => $dataSetting['targetHostName'],
            'driver' => 'pdo_mysql'
        );
        $config = new Configuration();
        try {
            $conn = DriverManager::getConnection($connectionParams, $config);
            $isConnected = $conn->connect();
//if connection successful add
            if ($isConnected) {
                $stm = "UPDATE `cspro_dictionaries_schema` SET `host_name` =  :targetHostName, `schema_name` =  :targetSchemaName,"
                        . " `schema_user_name` = :dbUserName, `schema_password` = AES_ENCRYPT(:dbPassword, :keyString), "
                        . " `map_info` = :mapInfo"
                        . " WHERE `dictionary_id` = :id";

                $bind['id'] = $dataSetting['id'];
                $bind['targetHostName'] = $dataSetting['targetHostName'];
                $bind['targetSchemaName'] = $dataSetting['targetSchemaName'];
                $bind['dbUserName'] = $dataSetting['dbUserName'];
                $bind['dbPassword'] = $dataSetting['dbPassword'];
                $bind['mapInfo'] = json_encode($dataSetting['mapInfo']);
                $bind['keyString'] = 'cspro';
                $stmt = $this->pdo->prepare($stm);
                $stmt->execute($bind);
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed updating configuration: " . $e->getMessage());
            throw $e;
        }
        $flag = isset($conn) ? true : false;
        return $flag;
    }

    public function getDataCounts(&$dataSettings) {
//get each dictionary get the counts in the source and target schema
        foreach ($dataSettings as &$dataSetting) {
            $dataSetting['totalCases'] = "";
            $dataSetting['processedCases'] = "";
            $dataSetting['lastProcessedTime'] = "";

            if (isset($dataSetting['targetSchemaName'])) {
                $stm = "SELECT count(*) FROM `" . $dataSetting['name'] . "` WHERE `deleted` = 0";
                $caseCount = (int) $this->pdo->fetchValue($stm);
                $dataSetting['totalCases'] = $caseCount;

//get number of cases processsed.
                $connectionParams = array(
                    'dbname' => $dataSetting['targetSchemaName'],
                    'user' => $dataSetting['dbUserName'],
                    'password' => $dataSetting['dbPassword'],
                    'host' => $dataSetting['targetHostName'],
                    'driver' => 'pdo_mysql',
                );
                $config = new Configuration();
                try {
                    $conn = DriverManager::getConnection($connectionParams, $config);

//get processsed case count 
                    $dataSetting['processedCases'] = 0;
                    $statement = $conn->executeQuery('SELECT count(*) FROM `cases` where `deleted`=0');
                    $processedCases = $statement->fetchColumn();
                    $dataSetting['processedCases'] = $processedCases;

//get processed time (modified time) from the most recently processed job
                    $statement = $conn->executeQuery('SELECT id , modified_time FROM `cspro_jobs` WHERE id = (SELECT max(id) from cspro_jobs where status =2)');
                    $dataSetting['lastProcessedTime'] = $statement->fetchColumn(1);
                } catch (\Exception $e) {
                    if (strpos((string) $e, 'SQLSTATE[42S02]') == FALSE) {
                        $this->logger->error('Failed getting case counts and last processed time', array("context" => (string) $e));
                    }
                }
            }
        }
        return $dataSettings;
    }

    function deleteDataSetting($dictionaryId): bool {
        try {
            $stm = 'DELETE FROM `cspro_dictionaries_schema` WHERE dictionary_id = :id';
            $bind['id'] = $dictionaryId;
            $row_count = $this->pdo->fetchAffected($stm, $bind);
            return $row_count;
        } catch (\Exception $e) {
            $this->logger->error('Failed deleting configuration. Dictionary Id: ' . $dictionaryId, array("context" => (string) $e));
            throw $e;
        }
    }

}
