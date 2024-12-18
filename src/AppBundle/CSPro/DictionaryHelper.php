<?php

namespace AppBundle\CSPro;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\VectorClock;
use AppBundle\CSPro\SyncHistoryEntry;
use AppBundle\CSPro\Dictionary;
use AppBundle\CSPro\Dictionary\Level;
use AppBundle\CSPro\Dictionary\Record;
use AppBundle\CSPro\Dictionary\Item;
use AppBundle\CSPro\Dictionary\ValueSet;
use AppBundle\CSPro\Dictionary\Value;
use AppBundle\CSPro\Dictionary\ValuePair;
use AppBundle\CSPro\Data;

class DictionaryHelper {

    private $logger;
    private $pdo;
    private $serverDeviceId;

    public function __construct(PdoHelper $pdo, LoggerInterface $logger, $serverDeviceId) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->serverDeviceId = $serverDeviceId;
    }

    public function tableExists($table) {
        try {
            $result = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (\Exception $e) {
            return false;
        }
        // ALW - By default PDO will not throw exceptions, so check result also.
        return $result !== false;
    }

    function dictionaryExists($dictName) {
        $stm = 'SELECT id  FROM cspro_dictionaries WHERE dictionary_name = :dictName;';
        $bind = array(
            'dictName' => array(
                'dictName' => $dictName
            )
        );
        return $this->pdo->fetchValue($stm, $bind);
    }

    function checkDictionaryExists($dictName) {
        if ($this->dictionaryExists($dictName) == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }
    }

    function loadDictionary($dictName) {
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            $bFound = false;
            $dict = apcu_fetch($dictName, $bFound);
            if ($bFound == true)
                return $dict;
        }
        $stm = 'SELECT dictionary_full_content FROM cspro_dictionaries WHERE dictionary_name = :dictName;';
        $bind = array(
            'dictName' => array(
                'dictName' => $dictName
            )
        );
        $dictText = $this->pdo->fetchValue($stm, $bind);
        if ($dictText == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }

        $parser = new \AppBundle\CSPro\Dictionary\Parser ();
        try {
            $dict = $parser->parseDictionary($dictText);
            if (extension_loaded('apcu') && ini_get('apc.enabled')) {
                apcu_store($dictName, $dict);
            }
            return $dict;
        } catch (\Exception $e) {
            $this->logger->error('Failed loading dictionary: ' . $dictName, array("context" => (string) $e));
            throw new HttpException(400, 'dictionary_invalid: ' . $e->getMessage());
        }
    }

    function getPossibleLatLongItemList($dictionary) {
        //loop through single record items including ID items for items that are decimal with at least one decimal digit.
        $level = $dictionary->getLevels()[0];
        $result = array();

        //loop through id items 
        for ($iItem = 0; $iItem < count($level->getIdItems()); $iItem++) {
            $item = $level->getIdItems()[$iItem];
            if ($item->getDataType() == 'Numeric' && $item->getDecimalPlaces() > 0) {
                $result[$item->getName()] = $item->getLabel();
            }
        }
        //loop through single records and get the decimal items 
        for ($iRecord = 0; $iRecord < count($level->getRecords()); $iRecord++) {
            $record = $level->getRecords()[$iRecord];
            if ($record->getMaxRecords() == 1) {
                for ($iItem = 0; $iItem < count($record->getItems()); $iItem++) {
                    $item = $record->getItems()[$iItem];
                    if ($item->getDataType() == 'Numeric' && $item->getDecimalPlaces() > 0) {
                        $result[$item->getName()] = $item->getLabel();
                    }
                }
            }
        }
        return $result;
    }

    function getItemsForMapPopupDisplay($dict) {

        $popupItemsMap = array();
        //getIdItems 
        $level = $dict->getLevels()[0];

        $idItemArray = array();
        for ($iItem = 0; $iItem < count($level->getIdItems()); $iItem++) {
            $idItem = $level->getIdItems()[$iItem];
            $idItemArray[strtoupper($idItem->getName())] = $idItem->getLabel();
        }
        $popupItemsMap["Record"][] = array('id' => 'Id Items', 'items' => $idItemArray);
        for ($iRecord = 0; $iRecord < count($level->getRecords()); $iRecord++) {
            $record = $level->getRecords()[$iRecord];
            if ($record->getMaxRecords() === 1) { //only single records. 
                $recordName = strtoupper($record->getName());
                $nameItemMap = array();
                $this->getRecordItemsNameMap($record, $nameItemMap);
                $itemNames = array_keys($nameItemMap);
                $itemArray = array();
                foreach ($itemNames as $itemName) {
                    $itemArray[strtoupper($itemName)] = $nameItemMap[$itemName]->getLabel();
                }
                $popupItemsMap["Record"][] = array($recordName => $record->getLabel(), 'items' => $itemArray);
            }
        }

        return $popupItemsMap;
    }

    function createDictionary($dict, $dictContent, &$csproResponse) {

        $dictName = $dict->getName();
        $dictLabel = $dict->getLabel();

        if ($this->dictionaryExists($dictName)) {
            $csproResponse->setError(405, 'dictionary_exists', "Dictionary {$dictName} already exists.");
            $csproResponse->setStatusCode(405);
            return;
        }
        // Make sure dict name contains only valid chars (letters, numbers and _)
        // This matches CSPro valid names and protects against SQL injection.
        // Note that PDO does not support using a prepared statement with table name as
        // parameter.
        if (!preg_match('/\A[A-Z0-9_]*\z/', $dictName)) {
            $csproResponse->setError(400, 'dictionary_name_invalid', "{$dictName} is not a valid dictionary name.");
            $csproResponse->setStatusCode(400);
            return;
        }

        $sql = <<<EOT
	CREATE TABLE IF NOT EXISTS `$dictName` (
        `id` int(11) unsigned NOT NULL AUTO_INCREMENT UNIQUE,
	`guid` binary(16) NOT NULL,
	`caseids` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
	`label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`questionnaire` BLOB NOT NULL,
	`revision` int(11) unsigned NOT NULL,
	`deleted` tinyint(1) unsigned NOT NULL DEFAULT '0',
	`verified` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `clock` text COLLATE utf8mb4_unicode_ci NOT NULL,
	`modified_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	`created_time` timestamp DEFAULT '1971-01-01 00:00:00',
	partial_save_mode varchar(6) NULL,
	partial_save_field_name varchar(255) COLLATE utf8mb4_unicode_ci NULL,
	partial_save_level_key varchar(255) COLLATE utf8mb4_unicode_ci NULL,
	partial_save_record_occurrence SMALLINT NULL,
	partial_save_item_occurrence SMALLINT NULL,
	partial_save_subitem_occurrence SMALLINT NULL,
EOT;

        $trigName = 'tr_' . $dictName;
        $sql .= <<<EOT
	PRIMARY KEY (`guid`),
  	KEY `revision` (`revision`),
  	KEY `caseids` (`caseids`),
  	KEY `deleted` (`deleted`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
	CREATE TRIGGER  $trigName BEFORE INSERT ON  $dictName FOR EACH ROW SET NEW.`created_time` = CURRENT_TIMESTAMP;
EOT;
        $this->pdo->exec($sql);

        $stmt = $this->pdo->prepare("INSERT INTO cspro_dictionaries (`dictionary_name`,
								`dictionary_label`, `dictionary_full_content`) VALUES (:name,:label,:content)");
        $stmt->bindParam(':name', $dictName);
        $stmt->bindParam(':label', $dictLabel);
        $stmt->bindParam(':content', $dictContent);
        $stmt->execute();

        $this->createDictionaryNotes($dictName, $csproResponse);
        if ($csproResponse->getStatusCode() != 200) {
            $this->logger->debug('createDictionaryNotes: getStatusCode.' . $csproResponse->getStatusCode());
            return $csproResponse; // failed to create notes.
        }

        $csproResponse = $csproResponse->setContent(json_encode(array(
            "code" => 200,
            "description" => 'Success'
        )));
        $csproResponse->setStatusCode(200);
    }

    function createDictionaryNotes($dictName, &$csproResponse) {
        $notesTableName = $dictName . '_notes';
        // check if the notes table if it does not exist
        $sql = <<<EOT
	CREATE TABLE IF NOT EXISTS `$notesTableName` (
	`id` SERIAL PRIMARY KEY ,
	`case_guid` binary(16)  NOT NULL,
	`operator_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`field_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`level_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
	`record_occurrence` SMALLINT NOT NULL,
	`item_occurrence`  SMALLINT NOT NULL,
    `subitem_occurrence` SMALLINT NOT NULL,
	`content` TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
	`modified_time` datetime NOT NULL,
	`created_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (`case_guid`)
        REFERENCES `$dictName`(`guid`)
		ON DELETE CASCADE
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOT;
        try {
            $this->pdo->exec($sql);
        } catch (\Exception $e) {
            $this->logger->error('Failed creating dictionary notes: ' . $notesTableName, array("context" => (string) $e));
            $csproResponse->setError(405, 'notes_table_createfail', $e->getMessage());
        }
    }

    function updateExistingDictionary($dict, $dictContent, &$csproResponse) {

        $dictName = $dict->getName();
        $dictLabel = $dict->getLabel();
        try {
            // Update dictionaries table with new label and content
            $stmt = $this->pdo->prepare("UPDATE cspro_dictionaries SET `dictionary_label`=:label, `dictionary_full_content`=:content WHERE `dictionary_name`=:name");
            $stmt->bindParam(':name', $dictName);
            $stmt->bindParam(':label', $dictLabel);
            $stmt->bindParam(':content', $dictContent);
            $stmt->execute();

            $csproResponse = $csproResponse->setContent(json_encode(array(
                "code" => 200,
                "description" => 'Success'
            )));
            $csproResponse->setStatusCode(200);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update dictionary: ' . $dictName, array("context" => (string) $e));
            $csproResponse->setError(500, 'dictionary_update_fail', $e->getMessage());
        }
    }

    function getLastSyncForDevice($dictName, $device) {
        try {
            $stm = 'SELECT revision AS revisionNumber, device, dictionary_name AS dictionary, universe, direction, cspro_sync_history.created_time as dateTime from cspro_sync_history JOIN cspro_dictionaries ON dictionary_id = cspro_dictionaries.id WHERE device=:device AND dictionary_name = :dictName ORDER BY revision DESC LIMIT 1';
            $bind = array(
                'device' => array(
                    'device' => $device
                ),
                'dictName' => array(
                    'dictName' => $dictName
                )
            );
            return $this->pdo->fetchObject($stm, $bind, 'AppBundle\CSPro\SyncHistoryEntry');
        } catch (\Exception $e) {
            throw new \Exception('Failed in getLastSyncForDevice ' . $dictName, 0, $e);
        }
    }

    function getSyncHistoryByRevisionNumber($dictName, $revisionNumber) {
        try {
            $stm = 'SELECT revision AS revisionNumber, device, dictionary_name AS dictionary, universe, direction, cspro_sync_history.created_time as dateTime from cspro_sync_history JOIN cspro_dictionaries ON dictionary_id = cspro_dictionaries.id WHERE revision = :revisionNumber AND dictionary_name = :dictName';
            $bind = array(
                'revisionNumber' => array(
                    'revisionNumber' => $revisionNumber
                ),
                'dictName' => array(
                    'dictName' => $dictName
                )
            );
            return $this->pdo->fetchObject($stm, $bind, 'AppBundle\CSPro\SyncHistoryEntry');
        } catch (\Exception $e) {
            throw new \Exception('Failed in getSyncHistoryByRevisionNumber ' . $dictName, 0, $e);
        }
    }

    // is universe more restrictive Or same as the previous revision
    function isUniverseMoreRestrictiveOrSame($currentUniverse, $lastRevisionUniverse) {
        // if the current universe is a sub string of last revision universe, they are not the same
        if ($currentUniverse === $lastRevisionUniverse) {
            return true;
        } else {
            return (strlen($currentUniverse) >= strlen($lastRevisionUniverse)) && substr($currentUniverse, 0, strlen($lastRevisionUniverse)) === $lastRevisionUniverse;
        }
    }

    // Add a new sync history entry to database and return the revision number
    function addSyncHistoryEntry($deviceId, $userName, $dictName, $direction, $universe = "") {
        //SELECT dictionary ID 
        $dictId = $this->dictionaryExists($dictName);
        if ($dictId == false) {
            throw new HttpException(404, "Dictionary {$dictName} does not exist.");
        }
        // insert a row into the sync history with the new version
        $stm = 'INSERT INTO cspro_sync_history (`device` , `username`, `dictionary_id`, `direction`, `universe`)
			 VALUES (:deviceId, :userName, :dictionary_id, :direction, :universe)';
        $bind = array(
            'deviceId' => array(
                'deviceId' => $deviceId
            ),
            'userName' => array(
                'userName' => $userName
            ),
            'dictName' => array(
                'dictName' => $dictName
            ),
            'universe' => array(
                'universe' => $universe
            ),
            'direction' => array(
                'direction' => $direction
            ),
            'dictionary_id' => array(
                'dictionary_id' => $dictId
            )
        );
        try {
            $this->pdo->perform($stm, $bind);
            $lastRevisionId = $this->pdo->lastInsertId();

            return $lastRevisionId;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw new \Exception('Failed to addSyncHistoryEntry ' . $dictName, 0, $e);
        }
    }

    //Delete Sync history entry
    function deleteSyncHistoryEntry($revision) {
        // delete entry in sync history for the revision
        $stm = $stm = 'DELETE FROM `cspro_sync_history` WHERE revision=:revision';
        $bind = array(
            'revision' => array(
                'revision' => $revision
            )
        );
        $deletedSyncHistoryCount = $this->pdo->fetchAffected($stm, $bind);
        $this->logger->debug('Deleted # ' . $deletedSyncHistoryCount . ' Sync History Entry revision: ' . $revision);
        return $deletedSyncHistoryCount;
    }

    // Select all the cases sent by the client that exist on the server
    function getLocalServerCaseList($dictName, $caseList) {
        if (count($caseList) == 0)
            return null;

        try {
            $this->checkDictionaryExists($dictName);
            // Select all the cases sent by the client that exist on the server
            $stm = 'SELECT  LCASE(CONCAT_WS("-", LEFT(HEX(guid), 8), MID(HEX(guid), 9,4), MID(HEX(guid), 13,4), MID(HEX(guid), 17,4), RIGHT(HEX(guid), 12))) as id,
			clock
			FROM ' . $dictName;
            $insertData = array();
            $ids = array();
            $strWhere = '';
            $n = 1;
            foreach ($caseList as $row) {
                $insertData [] = 'UNHEX(REPLACE(:guid' . $n . ',"-",""))';
                $ids ['guid' . $n] = $row ['id'];
                $n++;
            }
            // do bind values for the where condition
            if (count($insertData) > 0) {
                $inQuery = implode(',', $insertData);
                $stm .= ' WHERE `guid` IN (' . $inQuery . ');';
            }

            $stmt = $this->pdo->prepare($stm);

            $stmt->execute($ids);
            $result = $stmt->fetchAll();

            $localServerCases = array();
            foreach ($result as $row) {
                $localServerCases [$row ['id']] = $row;
            }
            return $localServerCases;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getLocalServerCaseList ' . $dictName, 0, $e);
        }
    }

    function reconcileCases(&$caseList, $localServerCases) {
        // fix the caseList.
        $defaultServerClock = new VectorClock(null);
        $defaultServerClock->setVersion($this->serverDeviceId, 1);
        $defaultJSONArrayServerClock = json_decode($defaultServerClock->getJSONClockString(), true);

        foreach ($caseList as $i => &$row) {
            $serverCase = isset($localServerCases, $localServerCases [$row ['id']]) ? $localServerCases [$row ['id']] : null;
            if (isset($serverCase)) {
                $strJSONServerClock = $serverCase ['clock'];
                $serverClock = new VectorClock(json_decode($strJSONServerClock, true));
                $clientClock = new VectorClock($row ['clock']); // the caselist row has decoded json array for the clock
                // compare clocks
                if ($clientClock->IsLessThan($serverClock)) {
                    // Local server case is more recent, do not update
                    // Remove this case from the $caseList.
                    // echo 'client clock less than server clock';
                    unset($caseList [$i]);
                    continue;
                } else if ($serverClock->IsLessThan($clientClock)) {
                    // Update is newer, replace the local server case
                    // do nothing. $row in the caseList will update the server case. client clock will be updated on the server.
                    // echo 'server clock less than client clock';
                } else if (!$serverClock->IsEqual($clientClock)) {
                    // Conflict - neither clock is greater - always use the client case and merge the clocks
                    // merge the clocks
                    // echo 'conflict! ';
                    $serverClock->merge($clientClock);
                    // update the case using the merged clock
                    $row ['clock'] = json_decode($serverClock->getJSONClockString(), true);
                }
            }
            if (count($row ['clock']) == 0) { // set the server default clock for updates or inserts if the clock sent is empty
                $row ['clock'] = $defaultJSONArrayServerClock;
            }
        }
        unset($row);
        // remove cases that have been discarded.
        $caseList = array_filter($caseList);
    }

    function prepareJSONForInsertOrUpdate($dictName, &$caseList) {
        // for each row get the record list array to multi-line string for the questionnaire data
        // Get the clocks for the cases on the server.
        // get local server cases
        $localServerCases = $this->getLocalServerCaseList($dictName, $caseList);
        // reconcile server cases with the client
        $this->reconcileCases($caseList, $localServerCases);
        foreach ($caseList as &$row) {
            if (isset($row['data'])) {
                $row ['data'] = implode("\n", $row ['data']); // for pre 7.5 blob data
            } else {
                //https://stackoverflow.com/questions/24607493/mysql-compress-vs-php-gzcompress
                //php gzcompress and MySQL uncompress differ in the header the static header below works fine 
                //with 4 bytes  zlib.org/rfc-gzip.html, with header 1F 8B 08 00 = ID1|ID2|CM |FLG
                //if this has issues in future use the commented line below which adds the leading 4 bytes with original size of the string
//                  $insertData ['questionnaire' . $n] = pack('V', mb_strlen($row ['level-1'])) . gzcompress($row ['level-1']); // CSPro 7.5+
                $row ['level-1'] = "\x1f\x8b\x08\x00" . gzcompress($row ['level-1']);
            }
            $row ['deleted'] = ( isset($row ['deleted']) && (1 == $row ['deleted'])) ? true : false;
            $row ['verified'] = (isset($row ['verified']) && (1 == $row ['verified'])) ? true : false;
            $row ['clock'] = json_encode($row ['clock']); // convert the json array clock to json string
            if (!isset($row['label'])) // allow null labels
                $row['label'] = '';
        }
        unset($row);
    }

    function isJsonQuestionnaire($case) {
        $len = strlen($case);
        return $len >= 2 && $case[0] == '{' && $case[$len - 1] == '}';
    }

    function prepareResultSetForJSON(&$caseList) {
        // for each row get the record list array to multi-line string for the questionnaire data
        foreach ($caseList as &$row) {
            unset($row['revision']);
            // Json formatted needs to be under 'level-1' key
            $row['level-1'] = gzuncompress(substr($row['data'], 4));
            unset($row['data']);

            $row ['deleted'] = (1 == $row ['deleted']) ? true : false;
            $row ['verified'] = (1 == $row ['verified']) ? true : false;
            if (isset($row ['partial_save_mode'])) {
                $row ['partialSave'] = array(
                    "mode" => $row ['partial_save_mode'],
                    "field" => array(
                        "name" => $row ['partial_save_field_name'],
                        "levelKey" => $row ['partial_save_level_key'],
                        "recordOccurrence" => intval($row ['partial_save_record_occurrence']),
                        "itemOccurrence" => intval($row ['partial_save_item_occurrence']),
                        "subitemOccurrence" => intval($row ['partial_save_subitem_occurrence'])
                    )
                );
            } else {
                unset($row ['partialSave']);
            }
            // unset partial_save_ ... columns
            unset($row ['partial_save_mode']);
            unset($row ['partial_save_field_name']);
            unset($row ['partial_save_level_key']);
            unset($row ['partial_save_record_occurrence']);
            unset($row ['partial_save_item_occurrence']);
            unset($row ['partial_save_subitem_occurrence']);

            if (empty($row ['clock']))
                $row ['clock'] = array();
            else
                $row ['clock'] = json_decode($row ['clock']);

            if (isset($row ['lastModified'])) {
                $lastModifiedUTC = DateTime::createFromFormat('Y-m-d H:i:s', $row ['lastModified'], new \DateTimeZone("UTC"));
                $row ['lastModified'] = $lastModifiedUTC->format(DateTime::RFC3339);
            }
        }
        unset($row);
    }

    //returns columns in area names table it exists otherwise returns -1
    function getAreaNamesColumnCount() {
        $columnCount = -1;
        $selectStm = "select database()";

        try {
            $query = $this->pdo->prepare($selectStm);
            $query->execute();
            $databaseName = $query->fetchColumn();
            $selectStm = "SELECT COUNT(*) FROM `information_schema`.`columns` WHERE `table_schema` = '$databaseName' AND `table_name` LIKE '%cspro_area_names%'";
            $query = $this->pdo->prepare($selectStm);
            $query->execute();
            $columnCount = $query->fetchColumn();
        } catch (\Exception $e) {
            throw new \Exception('Failed getting area names column count ', 0, $e);
            $this->logger->error('Failed getting area names column count ', array("context" => (string) $e));
        }

        return $columnCount;
    }

    function formatMapMarkerInfo($dict, $caseJSON, $markerItemList) {
        $mapMarkerInfo = array();
        $this->logger->debug("printing marker info " . print_r($markerItemList, true));
        if (isset($caseJSON["level-1"])) {
            $mapMarkerInfo["Case"] = $caseJSON["caseids"];
            $questionnaireJSON = json_decode($caseJSON["level-1"], true);
            $iLevel = 0;
            $level = $dict->getLevels()[$iLevel];
            //for each item in the id record 
            $nameItemMap = array();
            for ($iItem = 0; $iItem < count($level->getIdItems()); $iItem++) {
                $this->getRecordItemNameMap($level->getIdItems()[$iItem], $nameItemMap);
            }
            $itemNames = array_keys($nameItemMap);
            $upperItemNames = array_map('strtoupper', $itemNames);

            for ($idItem = 0; $idItem < count($level->getIdItems()); $idItem++) {
                if (array_search($upperItemNames[$idItem], $markerItemList) !== false) {
                    $this->logger->debug("processing item " . $upperItemNames[$idItem]);
                    $value = isset($questionnaireJSON["id"][$upperItemNames[$idItem]]) ? $questionnaireJSON["id"][$upperItemNames[$idItem]] : "";
                    $mapMarkerInfo[$this->getDisplayText($level->getIdItems()[$idItem])] = $this->getItemValueDisplayText($level->getIdItems()[$idItem], $value);
                }
            }
            //loop through the single records 
            for ($iRecord = 0; $iRecord < count($level->getRecords()); $iRecord++) {
                $record = $level->getRecords()[$iRecord];
                $recordName = strtoupper($record->getName());
                if ($record->getMaxRecords() === 1) {//multiple records 
                    //loop through the records for items 
                    for ($iItem = 0; $iItem < count($record->getItems()); $iItem++) {
                        $item = $record->getItems()[$iItem];
                        $upperItemName = strtoupper($item->getName());
                        if (array_search($upperItemName, $markerItemList) !== false) {
                            //get the item name and value 
                            $value = isset($questionnaireJSON[$recordName][$upperItemName]) ? $questionnaireJSON[$recordName][$upperItemName] : "";
                            $mapMarkerInfo[$this->getDisplayText($item)] = $this->getItemValueDisplayText($item, $value);
                        }
                    }
                }
            }
        }
        return $mapMarkerInfo;
    }

    function formatCaseJSONtoHTML($dict, $caseJSON): string {
        //for each id item
        $caseHtml = "";
        //TODO: fix for multiple levels
        // $this->logger->debug('printing dictionary: ' . print_r($dict, true));
        $iLevel = 0;
        $level = $dict->getLevels()[$iLevel];
        if (isset($caseJSON["level-1"])) {
            if (isset($caseJSON["caseids"])) {
                $labelOrKey = isset($caseJSON["label"]) && !empty($caseJSON["label"]) ? trim($caseJSON["label"]) : trim($caseJSON["caseids"]);
                $caseHtml .= "<p class=\"c2h_level_name\">" . $labelOrKey . "</p>";
            }
            $questionnaireJSON = json_decode($caseJSON["level-1"], true);
            $caseHtml .= $this->formatCaseLevelJSONtoHTML($level, $questionnaireJSON);
            //loop through the records 
            for ($iRecord = 0; $iRecord < count($level->getRecords()); $iRecord++) {
                $record = $level->getRecords()[$iRecord];
                $record->setLevel($level);
                $caseHtml .= $this->formatRecordJSONtoHTML($record, $questionnaireJSON);
            }
        }

        if (isset($caseJSON['notes'])) {
            $caseHtml .= $this->formatCaseNotetoHTML($caseJSON['notes']);
        }
        return $caseHtml;
    }

    private function formatCaseLevelJSONtoHTML($level, $caseJSON): string {

        $levelIDsHtml = "";
        $levelIDsHtml .= "<p class=\"c2h_level_name\">" . $this->getDisplayText($level) . "</p>";

        $nameItemMap = array();
        for ($iItem = 0; $iItem < count($level->getIdItems()); $iItem++) {
            $this->getRecordItemNameMap($level->getIdItems()[$iItem], $nameItemMap);
        }
        $itemNames = array_keys($nameItemMap);
        $upperItemNames = array_map('strtoupper', $itemNames);

        //write id record header
        $levelIDsHtml .= "<table class=\"c2h_table\">";
        $levelIDsHtml .= "<tr>";
        for ($iItem = 0; $iItem < count($itemNames); $iItem++) {
            $levelIDsHtml .= "<td class=\"c2h_table_header\">";
            $levelIDsHtml .= $upperItemNames[$iItem] . ": " . $nameItemMap[$itemNames[$iItem]]->getLabel() . "</td>";
        }
        $levelIDsHtml .= "</tr>";

        for ($idItem = 0; $idItem < count($level->getIdItems()); $idItem++) {
            if (isset($caseJSON["id"][$upperItemNames[$idItem]])) {
                $values[] = $caseJSON["id"][$upperItemNames[$idItem]];
            } else {
                $values[] = "";
            }
        }
        $levelIDsHtml .= $this->formatDataRow($nameItemMap, $values, 1);
        $levelIDsHtml .= "</table>";
        return $levelIDsHtml;
    }

    private function formatRecordJSONtoHTML($record, $caseJson): string {

        $recordHtml = "<p class=\"c2h_record_name\">" . $this->getDisplayText($record) . "</p>";
        $nameItemMap = array();
        $this->getRecordItemsNameMap($record, $nameItemMap);
        $itemNames = array_keys($nameItemMap);

        //write  record header
        $recordHtml .= "<table class=\"c2h_table\">";
        $recordHtml .= "<tr>";
        for ($iItem = 0; $iItem < count($itemNames); $iItem++) {
            $recordHtml .= "<td class=\"c2h_table_header\">";
            $recordHtml .= strtoupper($itemNames[$iItem]) . ": " . $nameItemMap[$itemNames[$iItem]]->getLabel() . "</td>";
        }
        $recordHtml .= "</tr>";

        $upperRecordName = strtoupper($record->getName());
        if (isset($caseJson[$upperRecordName])) {
            //if data rows available 
            if (isset($caseJson[$record->getName()])) {
                if ($record->getMaxRecords() > 1) {//multiple records 
                    $recordList = $caseJson[$upperRecordName];
                } else {//single record
                    $recordList[] = $caseJson[$upperRecordName];
                }
                //for each datarow
                $recordCount = 0;
                foreach ($recordList as $curRec) {
                    $recordCount++;
                    //set the values array for the record
                    $values = array();
                    //prepare item values
                    for ($iItem = 0; $iItem < count($record->getItems()); $iItem++) {
                        $item = $record->getItems()[$iItem];
                        if ($item->getItemType() === "Item") {
                            $parentItem = $item;
                            $item->setParentItem(null);
                        } else {
                            $item->setParentItem($parentItem);
                        }
                        $this->fillItemValues($item, $record, $curRec, $values);
                    }
                    //format data row
                    $recordHtml .= $this->formatDataRow($nameItemMap, $values, $recordCount);
                }
            }
        }
        $recordHtml .= "</table>";
        return $recordHtml;
    }

    function formatCaseNotetoHTML($caseNotes): string {
        //for each id item
        $caseNoteHtml = "";
        if (count($caseNotes) == 0) {
            return $caseNoteHtml;
        }

        $caseNoteHtml .= '<p class="c2h_record_name">Notes</p>';
        $caseNoteHtml .= '<table class="c2h_table">';
        $headerList = array('Field', 'Note', 'Operator ID', 'Date/Time');

        foreach ($headerList as $header) {
            $caseNoteHtml .= "<td class=\"c2h_table_header\">" . $header . "</td>";
        }
        $caseNoteHtml .= "</tr>";
        $index = 0;

        foreach ($caseNotes as $note) {
            $formatClass = "c2h_table_r" . $index % 2;
            $caseNoteHtml .= "<tr>";

            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['field']['name'] . "</td>";
            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['content'] . "</td>";
            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['operatorId'] . "</td>";
            $caseNoteHtml .= "<td class=\"" . $formatClass . "\">" . $note['modifiedTime'] . "</td>";

            $caseNoteHtml .= "</tr>";
            $index++;
        }

        $caseNoteHtml .= "</table>";
        return $caseNoteHtml;
    }

    public function fillItemValues(Item $item, Record $record, $curRecord, &$values) {
        $occurs = $item->getItemSubitemOccurs();
        $itemName = strtoupper($item->getName());
        $isNumeric = $item->getDataType() == 'Numeric';

        if ($occurs > 1) {
            $itemOccValues = array_fill(0, $occurs, "");
            if (isset($curRecord[$itemName])) {
                $itemValuesArray = $curRecord[$itemName];
                for ($iItemValue = 0; $iItemValue < count($itemValuesArray); $iItemValue++) {
                    $itemOccValues[$iItemValue] = $itemValuesArray[$iItemValue];
                    if ($isNumeric) {
                        if (is_numeric($itemValuesArray[$iItemValue]) === FALSE) {
                            $this->logger->warning("Record [" . $record->getName() . "] Item [$itemName] has invalid numeric value $itemValuesArray[$iItemValue].");
                        }
                    }
                }
            }
            $values = array_merge($values, $itemOccValues);
        } else {
            $insertValue = "";
            if (isset($curRecord[$itemName])) {
                $insertValue = $curRecord[$itemName];
                if ($isNumeric) {
                    if (is_numeric($curRecord[$itemName]) === FALSE) {
                        $this->logger->warning("Record [" . $record->getName() . "] Item [$itemName] has invalid numeric value $curRecord[$itemName]. Setting it to blank");
                    }
                }
            }
            $values[] = $insertValue;
        }
    }

    private function formatDataRow($itemNamesMap, $values, $row): string {
        $itemNames = array_keys($itemNamesMap);
        $formatClass = "c2h_table_r" . $row % 2;
        $dataRowHtml = "<tr>";

        for ($iItem = 0; $iItem < count($itemNames); $iItem++) {
            $dataRowHtml .= "<td class=\"" . $formatClass . "\">";
            $dataRowHtml .= $this->getItemValueDisplayText($itemNamesMap[$itemNames[$iItem]], $values[$iItem]) . "</td>";
        }
        $dataRowHtml .= "</tr>";

        return $dataRowHtml;
    }

    private function getDisplayText($dictBase): string {
        return $dictBase->getName() . ": " . $dictBase->getLabel();
    }

    private function getItemValueDisplayText(Item $dictItem, $value): string {
        //TODO: getDisplayText for alpha
        $isNumeric = $dictItem->getDataType() == 'Numeric';
        if ($isNumeric) {
            //get first vset
            if (count($dictItem->getValueSets()) > 0) {
                $dictValueSet = $dictItem->getValueSets()[0];

                $numValue = $dictItem->getDecimalPlaces() > 0 ? (float) $value : (int) $value;
                $label = $this->getValueLabelFromVset($dictValueSet, $numValue, $value);
                if (!empty($label) && trim($value) !== trim($label))
                    return empty($value) & $value !== 0 ? $label : $value . ": " . $label;
            }
        }
        return $value;
    }

    private function getValueLabelFromVset(ValueSet $dictValueSet, $value, $textValue): string {
        $this->logger->debug('printing valueSet: ' . print_r($dictValueSet, true));
        for ($iVal = 0; $iVal < count($dictValueSet->getValues()); $iVal++) {
            $dictValue = $dictValueSet->getValues()[$iVal];
            $this->logger->debug('printing value: ' . print_r($dictValue, true));
            $label = $dictValue["Label"];

            //check if value is special
            if (isset($dictValue["Special"])) {
                $this->logger->debug('special is: ' . $dictValue["VPairs"][0] . 'Value is: ' . $value . "label is :" . $label[0]);
                if ((trim($dictValue["VPairs"][0]) === trim($value)) || (trim($dictValue["VPairs"][0]) === trim($textValue))) {
                    $this->logger->debug('special value label is: ' . $label[0]);
                    return $label[0];
                }
            }
            if ($textValue === "")//text gets converted to numVal 0 do not process these. Only check for specials above.
                continue;
            //go through the vpairs
            for ($vPair = 0; $vPair < count($dictValue["VPairs"]); $vPair++) {
                $dictVPair = $dictValue["VPairs"][$vPair];
                if (getType($dictVPair) !== 'object') {
                    $this->logger->debug('printing vpair' . print_r($dictVPair, true));
                    continue;
                }
                $toVal = $dictVPair->getTo();
                $fromVal = $dictVPair->getFrom();

                if ($value == $fromVal) {
                    return $label[0];
                } elseif (isset($toVal)) {
                    if ($value > $fromVal && $value <= $toVal)
                        return $label[0];
                }
            }
        }
        return "";
    }

    private function getRecordItemsNameMap(Record $record, &$nameTypeMap) {

        for ($iItem = 0; $iItem < count($record->getItems()); $iItem++) {
            $item = $record->getItems()[$iItem];
            if ($item->getItemType() === "Item") {
                $parentItem = $item;
                $item->setParentItem(null);
            } else {
                $item->setParentItem($parentItem);
            }
            $this->getRecordItemNameMap($item, $nameTypeMap);
        }
    }

    public function getRecordItemNameMap(Item $item, &$nameTypeMap) {
        $itemName = strtolower($item->getName());
        $itemOccurrences = $item->getItemSubitemOccurs();

        if ($itemOccurrences == 1) {
            $nameTypeMap[$itemName] = $item;
        } else {
            for ($occurrence = 1; $occurrence <= $itemOccurrences; $occurrence++) {
                $itemNameWithOccurrence = $itemName . '(' . $occurrence . ')';
                $nameTypeMap[$itemNameWithOccurrence] = $item;
            }
        }
    }

}
