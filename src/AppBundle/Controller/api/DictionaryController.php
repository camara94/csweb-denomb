<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use AppBundle\CSPro\UploadCasesJsonListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\CSProJsonValidator;
use AppBundle\Security\DictionaryVoter;

class DictionaryController extends Controller implements ApiTokenAuthenticatedController {

    private $logger;
    private $pdo;
    private $oauthService;
    private $dictHelper;
    private $serverDeviceId;
    private $tokenStorage;

    public function __construct(OAuthHelper $oauthService, PdoHelper $pdo, TokenStorageInterface $tokenStorage, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->oauthService = $oauthService;
        $this->tokenStorage = $tokenStorage;
    }

    //overrider the setcontainer to get access to container parameters and initiailize the dictionary helper
    public function setContainer(ContainerInterface $container = null) {
        parent::setContainer($container);

        $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
        $this->serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
        $this->dictHelper = new DictionaryHelper($this->pdo, $this->logger, $this->serverDeviceId);
    }

    /**
     * @Route("/dictionaries/", methods={"GET"})
     */
    function getDictionaryList(Request $request) {
        $stm = 'SELECT dictionary_name as name, dictionary_label as label
		FROM cspro_dictionaries';
        $result = $this->pdo->fetchAll($stm);

        foreach ($result as &$row) {
            $table = $row ['name'];
            if ($this->dictHelper->tableExists($table)) {
                $stm = 'SELECT COUNT(*) as caseCount FROM ' . $table . ' WHERE deleted = 0';
                $row ['caseCount'] = (int) $this->pdo->fetchValue($stm);
            } else {
                $row ['caseCount'] = 0;
            }
        }
        unset($row);
        $response = new CSProResponse(json_encode($result));
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }

    /**
     * @Route("/dictionaries/", methods={"POST"})
     */
    function addDictionary(Request $request) {
        ///add dictionary only if permitted
        $this->denyAccessUnlessGranted(DictionaryVoter::DICTIONARY_OPERATIONS);

        $dictContent = $request->getContent();
        $response = new CSProResponse ();

        $parser = new \AppBundle\CSPro\Dictionary\Parser ();
        try {
            $dict = $parser->parseDictionary($dictContent);
        } catch (\Exception $e) {
            $response->setError(400, 'dictionary_invalid', $e->getMessage());
            $response->setStatusCode(400);
            return $response;
        }

        $dictName = $dict->getName();

        if ($this->dictHelper->dictionaryExists($dictName)) {
            $this->dictHelper->updateExistingDictionary($dict, $dictContent, $response);
        } else {
            $this->dictHelper->createDictionary($dict, $dictContent, $response);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/dictionaries/{dictName}/syncspec", methods={"GET"})
     */
    function getDictionarySyncSpec(Request $request, $dictName) {
        $this->denyAccessUnlessGranted(DictionaryVoter::DATA_DOWNLOAD);

        $this->dictHelper->checkDictionaryExists($dictName);
        $syncURL = $this->container->getParameter('cspro_rest_api_url');
        $csproVersion = $this->container->getParameter('cspro_version');
        $csproVersion = substr($csproVersion, 0, 3); //get {Major}.{Minor} version
        $syncSpec = chr(239) . chr(187) . chr(191); //BOM
        $syncSpec .= "[Run Information]" . "\r\n";
        $syncSpec .= "Version=" . $csproVersion . "\r\n";
        $syncSpec .= "AppType=Sync" . "\r\n";
        $syncSpec .= "\r\n";
        $syncSpec .= "[ExternalFiles]" . "\r\n";
        $syncSpec .= strtoupper($dictName) . '=' . strtolower($dictName) . '.csdb' . "\r\n";
        $syncSpec .= "\r\n";
        $syncSpec .= "[Parameters]" . "\r\n";
        $syncSpec .= "SyncDirection=Get" . "\r\n";
        $syncSpec .= "SyncType=CSWeb" . "\r\n";
        $syncSpec .= "SyncUrl=" . $syncURL . "\r\n";
        $syncSpec .= "Silent=No" . "\r\n";

        $response = new CSProResponse($syncSpec);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        $response->headers->set('Content-Type', 'text/plain');
        $response->setCharset('utf-8');
        $filename = strtolower($dictName) . ".pff";
        $contentDisposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Disposition', $contentDisposition);

        return $response;
    }

    /**
     * @Route("/dictionaries/{dictName}", methods={"GET"})
     */
    function getDictionary(Request $request, $dictName) {
        $stm = 'SELECT dictionary_full_content FROM cspro_dictionaries WHERE dictionary_name = :dictName;';
        $bind = array(
            'dictName' => array(
                'dictName' => $dictName
            )
        );
        $dictText = $this->pdo->fetchValue($stm, $bind);
        if ($dictText == false) {
            $response = new CSProResponse();
            $response->setError(404, "Dictionary {$dictName} does not exist");
            $response->headers->set('Content-Length', strlen($response->getContent()));
        } else {
            $response = new CSProResponse($dictText);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $response->headers->set('Content-Type', 'text/plain');
            $response->setCharset('utf-8');
        }

        return $response;
    }

    /**
     * @Route("/dictionaries/{dictName}", methods={"DELETE"})
     */
    function deleteDictionary(Request $request, $dictName) {

        $this->dictHelper->checkDictionaryExists($dictName);
        $this->logger->notice('Deleting dictionary: ' . $dictName);
        $this->denyAccessUnlessGranted(DictionaryVoter::DICTIONARY_OPERATIONS);

        try {
            // return the cases that are >old revision# and <> new revision#
            $this->pdo->beginTransaction();

            // Get the dictionary ID from the dictionary table;
            $stm = $stm = 'SELECT id FROM cspro_dictionaries  WHERE dictionary_name = :dictName';
            $bind = array();
            $bind['dictName'] = $dictName;

            $dictID = $this->pdo->fetchValue($stm, $bind);

            // delete sync history
            $stm = $stm = 'DELETE FROM `cspro_sync_history` WHERE dictionary_id=:dictID';
            $bind = array();
            $bind['dictID'] = $dictID;

            $deletedSyncHistoryCount = $this->pdo->fetchAffected($stm, $bind);

            // delete dictionary from cspro_dictionaries table
            $stm = 'DELETE	FROM cspro_dictionaries  WHERE dictionary_name = :dictName';
            unset($bind);
            $bind ['dictName'] = $dictName;
            $sth = $this->pdo->prepare($stm);
            $sth->execute($bind);

            // DROP TABLE dictionary_notes;
            $stm = 'DROP TABLE IF EXISTS ' . $dictName . '_notes;';
            $result = $this->pdo->query($stm);

            $this->logger->notice('Dropped dictionary notes table: ' . $dictName . '_notes');

            // DROP TABLE dictionary;
            $stm = 'DROP TABLE IF EXISTS ' . $dictName;
            $result = $this->pdo->query($stm);

            $this->logger->notice('Dropped dictionary table: ' . $dictName);

            $this->pdo->commit();

            unset($result);
            $result ['code'] = 200;
            $result ['description'] = 'Success';
            $response = new CSProResponse(json_encode($result));
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $this->logger->notice('Deleted  dictionary: ' . $dictName);
        } catch (\Exception $e) {
            $this->logger->error('Failed deleting dictionary: ' . $dictName, array("context" => (string) $e));
            $this->pdo->rollBack();

            $response = new CSProResponse ();
            $response->setError(500, 'dictionary_delete_error', 'Failed deleting dictionary');
            $response->headers->set('Content-Length', strlen($response->getContent()));
        }

        return $response;
    }

    // Syncs

    /**
     * @Route("/dictionaries/{dictName}/syncs", methods={"GET"})
     */
    function getSyncHistory(Request $request, $dictName) {
        $from = $request->get('from');
        $to = $request->get('to');
        $device = $request->get('device');
        $limit = $request->get('limit');
        $offset = $request->get('offset');

        return new CSProResponse('How about implementing getSyncHistory as a GET method ?');
    }

    /**
     * @Route("/dictionaries/{dictName}/syncs", methods={"POST"})
     */
    function syncCases(Request $request, $dictName) {
        return new CSProResponse('Method Not Allowed', 405);
    }

    // get cases

    /**
     * @Route("/dictionaries/{dictName}/cases", methods={"GET"})
     */
    function getCases(Request $request, $dictName) {

        $maxScriptExecutionTime = $this->container->getParameter('csweb_api_max_script_execution_time');
        ini_set('max_execution_time', $maxScriptExecutionTime);

        $this->dictHelper->checkDictionaryExists($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_download', $dictName);

        $response = new CSProResponse ();

        $universe = $request->headers->get('x-csw-universe');
        $universe = trim($universe, '"');
        $excludeRevisions = $request->headers->get('x-csw-exclude-revisions');

        //getCases Has eTag and deviceName
        // Check x-csw-if-revision-exists   header to see if this an update to a previous sync
        $lastRevision = null;
        $lastRevision = $request->headers->get('x-csw-if-revision-exists');
        if (empty($lastRevision))
            $lastRevision = 0;
        //get the custome headers 
        $startAfterGuid = $request->headers->get('x-csw-case-range-start-after');
        $rangeCount = $request->headers->get('x-csw-case-range-count');

        if (!empty($rangeCount)) {
            $rangeCount = trim($rangeCount, ' /');
            $this->logger->debug('range count' . $rangeCount);
            if (!is_numeric($rangeCount) || $rangeCount < 0) {
                $this->logger->error('Invalid range count' . $rangeCount);
                $response->setError(400, 'invalid_range_count', 'Invalid range count header');
                $response->headers->set('Content-Length', strlen($response->getContent()));
                return $response;
            }
            $rangeCount = (int) $rangeCount;
        }

        //Get the maxFileRevision
        $maxRevision = $this->getMaxRevisionNumber();
        if (!$maxRevision)
            $maxRevision = 1;

        if ($maxRevision < $lastRevision) {
            $response->headers->set('ETag', $maxRevision);
            $response = new CSProResponse(json_encode(array(), JSON_FORCE_OBJECT), 412);
            return $response;
        }
        //Get the cases requested, if it is less than the number of cases to be sent set the response code to 206
        //otherwise set to 200 
        //set the header content with #cases/#totalCases.
        #set the ETag with the new maxFileRevision 
        $response = new StreamedResponse();
        //get max revision for chunk
        $bind = array();
        $bind['lastRevision'] = $lastRevision;
        $bind['maxRevision'] = $maxRevision;
        $strWhere = '';
        $universe = $request->headers->get('x-csw-universe');
        $universe = trim($universe, '"');

        $startAfterGuid = empty($startAfterGuid) ? $startAfterGuid = '' : $startAfterGuid;

        if (!empty($startAfterGuid)) {
            $strWhere = ' WHERE ((revision = :lastRevision AND  guid > (UNHEX(REPLACE(:case_guid' . ',"-",""))))  OR revision > :lastRevision) AND revision <= :maxRevision ';
            $bind['case_guid'] = $startAfterGuid;
        } else {
            $strWhere = ' WHERE (revision > :lastRevision AND revision <= :maxRevision) ';
        }


        if (!empty($excludeRevisions)) {
            $arrExcludeRevisions = explode(',', $excludeRevisions);
            $strWhere .= ' AND revision NOT IN (:exclude_revisions) ';
            $bind['exclude_revisions'] = $arrExcludeRevisions;
        }
        //universe condition
        $strUniverse = '';
        if (!empty($universe)) {
            $strUniverse = ' AND (caseids LIKE :universe) ';
            $universe .= '%';
            $bind['universe'] = $universe;
        }

        $maxRevisionForChunk = $maxRevision;
        if (isset($rangeCount) && $rangeCount > 0) {
            $strChunkQuery = '( SELECT revision from ' . $dictName;
            $stm = $strChunkQuery . $strWhere . $strUniverse . ' ORDER BY revision LIMIT :rangeCount ) AS T1';
            $stm = 'SELECT max(revision) FROM ' . $stm;
            $bind['rangeCount'] = $rangeCount;
            $this->logger->debug('max revision for chunk: ' . $stm);
            $maxRevisionForChunk = $this->getMaxRevisionNumberForChunk($stm, $bind);
            unset($bind['rangeCount']);
            if ($maxRevisionForChunk <= 0)
                $maxRevisionForChunk = $maxRevision; //set it to the max revision of the full selection.
            $this->logger->debug('max revision for chunk: ' . $maxRevisionForChunk);
        }

        $dictController = $this;
        $response->setCallback(function () use ($request, $strWhere, $strUniverse, $bind, $maxRevisionForChunk, $rangeCount, $dictName, $dictController) {
            // return the cases that were added or modified since the lastRevision up to the maxrevision for chunk
            $bind['maxRevision'] = $maxRevisionForChunk;
            $maxCasesPerQuery = 10000; //Limit queries to 10000 rows in each request
            $limit = $maxCasesPerQuery;

            if (isset($rangeCount) && $rangeCount > 0 && $rangeCount < $limit)
                $limit = $rangeCount;
            $bind['limit'] = $limit;

            // the statement to prepare
            $strQuery = 'SELECT LCASE(CONCAT_WS("-", LEFT(HEX(guid), 8), MID(HEX(guid), 9,4), MID(HEX(guid), 13,4), MID(HEX(guid), 17,4), RIGHT(HEX(guid), 12))) as id,
								   questionnaire as data, caseids, label, deleted, verified,
								   partial_save_mode, partial_save_field_name, partial_save_level_key,
								   partial_save_record_occurrence, partial_save_item_occurrence, partial_save_subitem_occurrence,
								   clock, revision FROM ';
            $strOrderBy = ' ORDER BY  revision,  guid  LIMIT :limit ';
            $stm = $strQuery . $dictName . $strWhere . $strUniverse . $strOrderBy;

            $this->logger->debug('query: ' . $stm);
            // do bind values. when universe or limit exist
            $strJSONResponse = '[';
            $totalCases = 0;
            while (true) {
                //query the DB  up to the #limit number of rows
                $result = $this->pdo->fetchAll($stm, $bind);

                if (count($result) > 0) {//the next iteration should use the caseid after the last retrieved
                    ///get the revision for this guid
                    $bind['case_guid'] = $result[count($result) - 1]['id'];
                    $bind['lastRevision'] = $result[count($result) - 1]['revision'];
                    $strWhere = ' WHERE ((revision = :lastRevision AND  guid > (UNHEX(REPLACE(:case_guid' . ',"-",""))))  OR revision > :lastRevision) AND revision <= :maxRevision ';
                    $stm = $strQuery . $dictName . $strWhere . $strUniverse . $strOrderBy;
                }

                // getCaseNotes from the notes table and add to each case
                // getCaseNotes assumes caseid guids are sorted in asc - the default order by for mysql
                $dictController->getCaseNotes($result, $dictName . '_notes');

                // for each row
                $this->dictHelper->prepareResultSetForJSON($result);
                //remove the trailing and leading list chars []
                $strJSONResponse .= trim(json_encode($result), "[]");

                echo $strJSONResponse;
                ob_flush();
                flush();
                $strJSONResponse = ','; //reset json string response and add the comma for the next batch

                $totalCases += count($result);
                if (count($result) < $limit) //finished processing the cases
                    break;

                //if we have sent the requested number of cases break.
                if ($rangeCount > 0 && $totalCases >= $rangeCount)
                    break;

                //reduce the limit if the limit is over the requested number of cases
                if ($rangeCount > 0)
                    $limit = min($rangeCount - $totalCases, $maxCasesPerQuery);

                $bind['limit'] = $limit; //set the new limit in the binding;
            }

            //finalize the strJSONResponse
            $strJSONResponse = trim($strJSONResponse, ",");
            $strJSONResponse .= ']';
            echo $strJSONResponse;
            ob_flush();
            flush();
            $strJSONResponse = ''; //reset json string response
        }
        );

        $caseCount = $dictController->getCaseCount($dictName, $universe, $excludeRevisions, $lastRevision, $maxRevision, $startAfterGuid);
        $strRangeHeader = empty($rangeCount) || $rangeCount > $caseCount ? $caseCount . '/' . $caseCount : $rangeCount . '/' . $caseCount;
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('x-csw-case-range-count', $strRangeHeader);
        $response->headers->set('ETag', $maxRevisionForChunk);
        $response->headers->set('x-csw-chunk-max-revision', $maxRevisionForChunk); //nginx  strips Etag, now using custom header

        if (!empty($rangeCount) && $rangeCount < $caseCount) { //sending partial content
            $response->setStatusCode(206);
        } else {//sending all the cases
            $response->setStatusCode(200);
        }

        return $response;
    }

    // Add a new case

    /**
     * @Route("/dictionaries/{dictName}/cases", methods={"POST"})
     */
    function addOrUpdateCases(Request $request, $dictName) {
        $maxScriptExecutionTime = $this->container->getParameter('csweb_api_max_script_execution_time');
        ini_set('max_execution_time', $maxScriptExecutionTime);

        $dict = $this->dictHelper->loadDictionary($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_upload', $dictName);

        $params = array();
        $content = $request->getContent();

        $maxJsonMemoryLimit = $this->container->getParameter('csweb_api_max_json_memory_limit');
        $json_memory_limit = $maxJsonMemoryLimit * 1024 * 1024;
        $json_size = strlen($content);
        $useParser = false;
        $userName = $this->tokenStorage->getToken()->getUsername();
        $this->logger->debug("JSON payload size $json_size");
        if (!empty($content)) {
            $stream = null;
            if ($json_size > $json_memory_limit) {
                $syncCasesListener = new UploadCasesJsonListener($this->pdo, $this->dictHelper, $this->logger, $this, $request, $userName, $dictName);
                $stream = fopen('php://temp', 'w+');
                if ($request->headers->get('Content-Encoding') == 'gzip') {
                    $this->logger->debug('Using stream filter to decompress sync data');
                    stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15]);  // window of 15 for RFC1950 format which is what client sends                
                }
                fwrite($stream, $content);
                $content = null;
                $useParser = true;
                rewind($stream);
            } else {
                if ($request->headers->get('Content-Encoding') == 'gzip') {
                    $this->logger->debug("Decompressing sync data");
                    $content = gzuncompress($content);
                }
                $syncCasesListener = new UploadCasesJsonListener($this->pdo, $this->dictHelper, $this->logger, $this, $request, $userName, $dictName, $content);
                $content = null;
            }
            try {//optimize for memory usage
                $this->pdo->beginTransaction();
                if ($useParser) {
                    $parser = new \JsonStreamingParser\Parser($stream, $syncCasesListener);
                    $syncCasesListener->setParser($parser);
                    $parser->parse();
                    fclose($stream);
                } else {//optmize for peformance
                    $syncCasesListener->processCasesInsertOrUpdateWithoutParsing();
                }
            } catch (\Exception $e) {
                if ($useParser)
                    fclose($stream);
                $this->logger->error('Failed Uploading Cases to dictionary: ' . $dictName, array("context" => (string) $e));
                $this->pdo->rollBack();
                //delete the added sync history entry when rolled back
                $this->dictHelper->deleteSyncHistoryEntry($syncCasesListener->currentRevisionNumber);

                $response = new CSProResponse ();
                $response->setError(500, 'upload_cases_error', 'Failed uploading cases');
                $response->headers->set('Content-Length', strlen($response->getContent()));
                return $response;
            }
            $response = $syncCasesListener->getResponse();
            if ($response == null) {
                $response = new CSProResponse ();
                $this->logger->error('Failed Uploading Cases to dictionary: response from listener is empty');
                $response->setError(500, 'upload_cases_error', 'Failed syncing cases');
                $response->headers->set('Content-Length', strlen($response->getContent()));
            }
            return $response;
        } else {
            $this->logger->error('Request content is Empty. Invalid sync request: ' . $dictName);
            $result ['code'] = 400;
            $result ['description'] = 'Invalid upload request';
            $response->setError($result ['code'], 'upload_cases_error', $result ['description']);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }
    }

    // Get a case

    /**
     * @Route("/dictionaries/{dictName}/cases/{caseId}", methods={"GET"})
     */
    function getCase(Request $request, $dictName, $caseId) {
        $this->dictHelper->checkDictionaryExists($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_download', $dictName);
        try {
            // the statement to prepare
            $stm = "SELECT LCASE(CONCAT_WS('-', LEFT(HEX(guid), 8), MID(HEX(guid), 9,4), MID(HEX(guid), 13,4), MID(HEX(guid), 17,4), RIGHT(HEX(guid), 12))) as id,
				questionnaire as data, caseids, label, deleted,verified,
				partial_save_mode, partial_save_field_name, partial_save_level_key, 
				partial_save_record_occurrence, partial_save_item_occurrence, partial_save_subitem_occurrence,
				clock
				FROM " . $dictName . ' WHERE guid =(UNHEX(REPLACE(:case_guid' . ',"-","")))';

            $bind = array();
            $bind['case_guid'] = $caseId;

            $result = $this->pdo->fetchAll($stm, $bind);
            // getCaseNotes from the notes table and add to each case
            // getCaseNotes assumes caseid guids are sorted in asc - the default order by for mysql
            $this->getCaseNotes($result, $dictName . '_notes');
            $this->dictHelper->prepareResultSetForJSON($result);
            if (!$result) {
                $response = new CSProResponse ();
                $response->setError(404, 'case_not_found', 'Case not found');
            } else {
                $resultCase = $result [0];
                $response = new CSProResponse(json_encode($resultCase));
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting case from dictionary: ' . $dictName, array("context" => (string) $e));
            $response = new CSProResponse ();
            $response->setError(500, 'failed_get_case', 'Failed getting case');
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    // Update a case

    /**
     * @Route("/dictionaries/{dictName}/cases/{caseId}", methods={"PUT"})
     */
    function updateCase(Request $request, $dictName, $caseId) {

        // TODO: assumes requests is JSON - revisit for implementing raw format
        $dict = $this->dictHelper->loadDictionary($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_upload', $dictName);

        $params = array();
        $content = $request->getContent();
        if (!empty($content)) {
            // Get the questionnaire from the case JSON object and convert the array of records to multi-line string
            $uri = '#/definitions/Case';
            $csproJsonValidator = new CSProJsonValidator($this->logger);
            $csproJsonValidator->validateEncodedJSON($content, $uri);

            $params = json_decode($content, true); // 2nd param to get as array
            $caseList = array();
            $caseList [] = $params;
            $this->dictHelper->prepareJSONForInsertOrUpdate($dictName, $caseList);

            if (count($caseList) == 0) {
                $result ['code'] = 200;
                $result ['description'] = 'Success';
                $response = new CSProResponse(json_encode($result));
                $response->headers->set('Content-Type', 'application/json');
                return $response;
            }
            $questionnaire = isset($caseList [0] ['level-1']) ? $caseList [0] ['level-1'] : $caseList [0] ['data'];
            $deleted = $caseList [0] ['deleted'] ? 1 : 0;
            $verified = $caseList [0] ['verified'] ? 1 : 0;
            $caseids = $caseList [0] ['caseids'];
            $label = $caseList [0] ['label'];
            $clock = $caseList [0] ['clock'];

            $partial_save_field_name = null;
            $partial_save_level_key = null;
            $partial_save_record_occurrence = null;
            $partial_save_item_occurrence = null;
            $partial_save_subitem_occurrence = null;
            $partial_save_mode = null;

            if (isset($caseList [0] ['partialSave'])) {
                $partial_save_mode = $caseList [0] ['partialSave'] ['mode'];
                if (isset($caseList [0] ['partialSave'] ['field'])) {
                    $partial_save_field_name = $caseList [0] ['partialSave'] ['field'] ['name'];
                    $partial_save_level_key = isset($caseList [0] ['partialSave'] ['field'] ['levelKey']) ? $caseList [0] ['partialSave'] ['field'] ['levelKey'] : "";
                    $partial_save_record_occurrence = isset($caseList [0] ['partialSave'] ['field'] ['recordOccurrence']) ? $caseList [0] ['partialSave'] ['field'] ['recordOccurrence'] : 0;
                    $partial_save_item_occurrence = isset($caseList [0] ['partialSave'] ['field'] ['itemOccurrence']) ? $caseList [0] ['partialSave'] ['field'] ['itemOccurrence'] : 0;
                    $partial_save_subitem_occurrence = isset($caseList [0] ['partialSave'] ['field'] ['subitemOccurrence']) ? $caseList [0] ['partialSave'] ['field'] ['subitemOccurrence'] : 0;
                }
            }

            // insert a row into the sync history with the new version
            $userName = $this->tokenStorage->getToken()->getUsername();
            $deviceId = $request->headers->get('x-csw-device');
            $currentRevisionNumber = $this->dictHelper->addSyncHistoryEntry($deviceId, $userName, $dictName, 'put');
            try {
                $this->pdo->beginTransaction();
                // the statement to prepare and update the questionnaire data
                $stm = 'UPDATE ' . $dictName . ' SET
								questionnaire = :questionnaire,
								caseids = :caseids,
								label = :label,
								revision = (SELECT IFNULL(MAX(revision),0) from cspro_sync_history WHERE device = :deviceId and dictionary_id = (SELECT id  FROM cspro_dictionaries WHERE dictionary_name = :dictName)),
								deleted  = :deleted,
								verified  = :verified,
								partial_save_mode = :partial_save_mode,
								partial_save_field_name = :partial_save_field_name,
								partial_save_level_key = :partial_save_level_key,
								partial_save_record_occurrence = :partial_save_record_occurrence,
								partial_save_item_occurrence = :partial_save_item_occurrence,
								partial_save_subitem_occurrence = :partial_save_subitem_occurrence,
								clock = :clock,' . implode(',', array_map(function ($n) {
                                    return "$n = :$n";
                                })) . ' WHERE guid=(UNHEX(REPLACE(:case_guid' . ',"-","")))';

                $bind = array();
                $bind['case_guid'] = $caseId;
                $bind['questionnaire'] = $questionnaire;
                $bind['caseids'] = $caseids;
                $bind['label'] = $label;
                $bind['deviceId'] = $deviceId;
                $bind['deleted'] = $deleted;
                $bind['verified'] = $verified;
                $bind['partial_save_mode'] = $partial_save_mode;
                $bind['partial_save_field_name'] = $partial_save_field_name;
                $bind['partial_save_level_key'] = $partial_save_level_key;
                $bind['partial_save_record_occurrence'] = $partial_save_record_occurrence;
                $bind['partial_save_item_occurrence'] = $partial_save_item_occurrence;
                $bind['partial_save_subitem_occurrence'] = $partial_save_subitem_occurrence;
                $bind['clock'] = $clock;
                $bind['dictName'] = $dictName;

                $row_count = $this->pdo->fetchAffected($stm, $bind);
                // add notes to the notes table
                $this->addCaseNotes($caseList, $dictName . '_notes');
                if ($row_count == 1) {
                    $this->pdo->commit();
                    $result ['code'] = 200;
                    $result ['description'] = 'Success';
                    $response = new CSProResponse(json_encode($result));
                    $response->headers->set('Content-Length', strlen($response->getContent()));
                } else {
                    $response = new CSProResponse ();
                    $response->setError(404, 'case_not_found', 'Case not found');
                }
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                //delete the added sync history entry when rolled back
                $this->dictHelper->deleteSyncHistoryEntry($currentRevisionNumber);

                $this->logger->error('Failed updating case to dictionary: ' . $dictName, array("context" => (string) $e));
                $response = new CSProResponse ();
                $response->setError(404, null, $e->getMessage());
            }
        } else {
            $response = new CSProResponse ();
            $response->setError(400, null, 'Invalid JSON Content');
        }

        return $response;
    }

    /**
     * @Route("/dictionaries/{dictName}/cases/{caseId}", methods={"DELETE"})
     */
    function deleteCase(Request $request, $dictName, $caseId) {
        $this->dictHelper->checkDictionaryExists($dictName);
        $this->denyAccessUnlessGranted('dictionary_sync_upload', $dictName);
        // insert a row into the sync history with the new revision
        $userName = $this->tokenStorage->getToken()->getUsername();
        $deviceId = $request->headers->get('x-csw-device');
        $currentRevisionNumber = $this->dictHelper->addSyncHistoryEntry($deviceId, $userName, $dictName, 'put');
        try {
            $this->pdo->beginTransaction();

            // the statement to prepare
            $stm = 'UPDATE ' . $dictName . ' SET
					deleted = 1,
					revision = (SELECT IFNULL(MAX(revision),0) from cspro_sync_history WHERE device = :deviceId and dictionary_id = (SELECT id  FROM cspro_dictionaries WHERE dictionary_name = :dictName)) ' . ' WHERE guid = (UNHEX(REPLACE(:case_guid' . ',"-","")))';
            $bind = array();
            $bind['deviceId'] = $deviceId;
            $bind['case_guid'] = $caseId;
            $bind['dictName'] = $dictName;

            $row_count = $this->pdo->fetchAffected($stm, $bind);

            if ($row_count == 1) {
                $this->pdo->commit();
                $result ['code'] = 200;
                $result ['description'] = 'Success';
                $response = new CSProResponse(json_encode($result));
                $response->headers->set('Content-Length', strlen($response->getContent()));
            } else {
                $response = new CSProResponse ();
                $response->setError(404, 'case_not_found', 'Case not found');
            }
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            //delete the added sync history entry when rolled back
            $this->dictHelper->deleteSyncHistoryEntry($currentRevisionNumber);

            $this->logger->error('Failed deleting case in dictionary: ' . $dictName, array("context" => (string) $e));
            $response = new CSProResponse ();
            $response->setError(404, 'failed_insert_case', $e->getMessage());
        }

        return $response;
    }

    function deleteCaseNotes($caseList, $notesTableName) {
        // delete all the notes for the cases in the caselist
        $stm = "DELETE	FROM " . $notesTableName . ' WHERE case_guid IN ( ';

        $whereData = array();
        $n = 0;
        // prepare the where clause in list for all the case guids to delete the notes for the correponding cases
        foreach ($caseList as $case) {
            $case_guid = $case ['id'];
            $strWhere [] = 'UNHEX(REPLACE(' . ":case_guid$n" . ',"-",""))';
            $whereData ['case_guid' . $n] = $case_guid;
            $n++;
        }

        if (!empty($strWhere)) {
            $stm .= implode(', ', $strWhere);
            $stm .= ' );';
            try {
                // return the cases that are >old revision# and <> new revision#
                // fetch notes for all the cases
                // $result = $this->pdo->fetchAll($stm,$whereData);
                $stmt = $this->pdo->prepare($stm);
                // Note: direct bind with fetchAll in Aura does not work right when doing UNHEX and REPLACE . Call first prepare and execute before doing fetchAll
                $result = $stmt->execute($whereData); // true if successful
            } catch (\PDOException $e) {
                // if table not found return otherwise throw the exception
                if ($e->getCode() != '42S02') { // any other error other than table or view not found
                    $this->logger->error('Failed deleting notes in: ' . $notesTableName, array("context" => (string) $e));
                    throw new \Exception('Failed deleting notes in: ' . $notesTableName, 0, $e);
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed deleting notes in: ' . $notesTableName, array("context" => (string) $e));
                throw new \Exception('Failed deleting notes in: ' . $notesTableName, 0, $e);
            }
        }
    }

    function addCaseNotes($caseList, $notesTableName) {
        // try deleting all the notes before inserting the new values
        try {
            $this->deleteCaseNotes($caseList, $notesTableName);
        } catch (\Exception $e) {
            $this->logger->error('Failed adding case notes in: ' . $notesTableName, array("context" => (string) $e));
            throw new \Exception('Failed adding case notes in: ' . $notesTableName, 0, $e);
        }

        // for each notes in the list insert values into notestTable
        $insertQuery = array();
        $insertData = array();
        $n = 0;
        $colNames = array(
            'field_name',
            'level_key',
            'record_occurrence',
            'item_occurrence',
            'subitem_occurrence',
            'content',
            'operator_id',
            'modified_time'
        );
        $stm = 'INSERT INTO ' . $notesTableName . ' (`case_guid`,' . implode(',', array_map(function ($col) {
                            return "`$col`";
                        }, $colNames)) . ') VALUES ';
        foreach ($caseList as $case) {
            $case_guid = $case ['id'];
            $notestList = isset($case ['notes']) ? $case ['notes'] : [];
            foreach ($notestList as $row) {
                $insertQuery [] = '(UNHEX(REPLACE(:case_guid' . $n . ',"-","")),' . implode(',', array_map(function ($col) use ($n) {
                                    return ":$col$n";
                                }, $colNames)) . ')';
                $insertData ['case_guid' . $n] = $case_guid;
                $insertData ['field_name' . $n] = $row ['field'] ['name'];
                $insertData ['level_key' . $n] = $row ['field'] ['levelKey'];
                $insertData ['record_occurrence' . $n] = $row ['field'] ['recordOccurrence'];
                $insertData ['item_occurrence' . $n] = $row ['field'] ['itemOccurrence'];
                $insertData ['subitem_occurrence' . $n] = $row ['field'] ['subitemOccurrence'];
                $insertData ['content' . $n] = $row ['content'];
                $insertData ['operator_id' . $n] = $row ['operatorId'];
                $insertData ['modified_time' . $n] = date('Y-m-d H:i:s', strtotime($row['modifiedTime']));
                $n++;
            }
        }
        if (!empty($insertQuery)) {
            $stm .= implode(', ', $insertQuery);
            $stm .= ';';
            try {
                // return the cases that are >old revision# and <> new revision#
                $stmt = $this->pdo->prepare($stm);
                $result = $stmt->execute($insertData); // true if successful
            } catch (\Exception $e) {
                $this->logger->error('Failed adding case notes in: ' . $notesTableName, array("context" => (string) $e));
                throw new \Exception('Failed adding case notes in: ' . $notesTableName, 0, $e);
            }
        }
    }

    // getCaseNotes- assumes cases are ordered by guids (ascending - default order of mysql)
    function getCaseNotes(&$caseList, $notesTableName) {
        // select all the notes for the cases in the caselist
        $stm = "SELECT id,
				LCASE(CONCAT_WS('-', LEFT(HEX(case_guid), 8), MID(HEX(case_guid), 9,4), MID(HEX(case_guid), 13,4), MID(HEX(case_guid), 17,4), RIGHT(HEX(case_guid), 12))) as case_guid, 
				field_name as name , level_key as levelKey,  record_occurrence as recordOccurrence, item_occurrence as itemOccurrence , subitem_occurrence as subitemOccurrence,
				content, operator_id as operatorId, modified_time as modifiedTime FROM " . $notesTableName . ' WHERE case_guid IN ( ';

        $whereData = array();
        $n = 0;
        // prepare the where clause in list for all the case guids to get the notes for the correponding cases
        foreach ($caseList as $case) {
            $case_guid = $case ['id'];
            $strWhere [] = 'UNHEX(REPLACE(' . ":case_guid$n" . ',"-",""))';
            $whereData ['case_guid' . $n] = $case_guid;
            $n++;
        }

        if (!empty($strWhere)) {

            $stm .= implode(', ', $strWhere);
            $stm .= ' ) ORDER  BY `case_guid` ;';

            try {
                // return the cases that are >old revision# and <> new revision#
                // fetch notes for all the cases
                // $result = $this->pdo->fetchAll($stm,$whereData);
                $stmt = $this->pdo->prepare($stm);
                // Note: direct bind with fetchAll in Aura does not work right when doing UNHEX and REPLACE . Call first prepare and execute before doing fetchAll
                $result = $stmt->execute($whereData); // true if successful

                $result = $stmt->fetchAll();

                //preprare notes list for cases 
                $caseNotes = array();
                foreach ($result as $note) {
                    if (isset($note['modifiedTime'])) {
                        // Convert to RFC3339 format and also convert from local time zone to UTC
                        $note['modifiedTime'] = gmdate(\DateTime::RFC3339, strtotime($note['modifiedTime']));
                    }
                    $caseNotes[$note['case_guid']][] = array(
                        "content" => $note ['content'],
                        "modifiedTime" => $note ['modifiedTime'],
                        "operatorId" => $note ['operatorId'],
                        "field" => array(
                            "name" => $note ['name'],
                            "levelKey" => $note ['levelKey'],
                            "recordOccurrence" => intval($note['recordOccurrence']),
                            "itemOccurrence" => intval($note ['itemOccurrence']),
                            "subitemOccurrence" => intval($note ['subitemOccurrence'])
                        )
                    );
                }
                // associate noteslist to cases
                foreach ($caseList as &$case) {
                    $case_guid = $case ['id'];
                    if (isset($caseNotes[$case_guid])) {
                        $case ['notes'] = $caseNotes[$case_guid];
                    } else {
                        $case ["notes"] = array();
                    }
                }
                unset($case);
            } catch (\Exception $e) {
                $this->logger->error('Failed getting case notes: ' . $notesTableName, array("context" => (string) $e));
                throw new \Exception('Failed getting case notes in: ' . $notesTableName, 0, $e);
            }
        }
    }

    function getMaxRevisionNumber() {
        try {
            //returns the max revision in the cspro_sync_history table
            //may not match the max revision of the dictionary cases table 
            $stm = 'SELECT max(revision)  FROM  cspro_sync_history';
            $maxRevison = (int) $this->pdo->fetchValue($stm);
            return $maxRevison;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getMaxRevisionNumber ', 0, $e);
        }
    }

    function getMaxRevisionNumberForChunk($stm, $bind) {
        try {
            $maxRevison = (int) $this->pdo->fetchValue($stm, $bind);
            return $maxRevison;
        } catch (\Exception $e) {
            throw new \Exception('Failed in getMaxRevisionNumberForChunk ', 0, $e);
        }
    }

    function getCaseCount($dictName, $universe, $excludeRevisions, $lastRevision, $maxRevision, $startAfterGuid) {
        try {
            if (empty($maxRevision)) {
                throw new \Exception('Failed in getCaseCount ' . $dictName . 'Expecting maxRevision to be set.');
            }
            $bind = array();

            $lastRevision = empty($lastRevision) ? 0 : $lastRevision;
            $strWhere = ' WHERE (revision > :lastRevision AND revision <= :maxRevision) ';

            $startAfterGuid = empty($startAfterGuid) ? $startAfterGuid = '' : $startAfterGuid;
            if (!empty($startAfterGuid)) {
                $strWhere = ' WHERE ((revision = :lastRevision AND  guid > (UNHEX(REPLACE(:case_guid' . ',"-",""))))  OR revision > :lastRevision) AND revision <= :maxRevision ';
                $bind['case_guid'] = $startAfterGuid;
            } else {
                $strWhere = ' WHERE (revision > :lastRevision AND revision <= :maxRevision) ';
            }

            $bind['lastRevision'] = $lastRevision;
            $bind['maxRevision'] = $maxRevision;

            if (!empty($excludeRevisions)) {
                $arrExcludeRevisions = explode(',', $excludeRevisions);
                $strWhere .= ' AND revision NOT IN (:exclude_revisions) ';
                $bind['exclude_revisions'] = $arrExcludeRevisions;
            }

            if (!empty($universe)) {
                $strWhere .= ' AND (caseids LIKE :universe) ';
                $universe .= '%';
                $bind['universe'] = $universe;
            }

            $stm = 'SELECT count(*)  FROM ' . $dictName . $strWhere;

            return (int) $this->pdo->fetchValue($stm, $bind);
        } catch (\Exception $e) {
            throw new \Exception('Failed in getCaseCount ' . $dictName, 0, $e);
        }
    }

}
