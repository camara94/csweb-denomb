<?php

namespace AppBundle\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\CSPro\Data\MapDataRepository;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\Data\DataSettings;

class ReportController extends Controller implements TokenAuthenticatedController {

    private $client;
    private $logger;
    private $mapDataRepository;
    private $pdo;

    public function __construct(HttpHelper $client, PdoHelper $pdo, LoggerInterface $logger) {
        $this->client = $client;
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->mapDataRepository = new MapDataRepository($this->pdo, $this->logger);
    }

    /**
     * @Route("/sync-report", name="sync-report", methods={"GET"})
     */
    public function viewSyncReportListAction(Request $request) {
        $this->logger->debug('displaying view sync report list');
        // Set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;
        // Won't return usable data for report. However, call is still made, so unauthorized or expired oauth
        // tokens can be redirected to logout page.
        $response = $this->client->request('GET', 'sync-report', null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        // Unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        // Return an empty array. A series of Ajax calls must be made when document is ready that will determine the
        // data query that will return usable data for report.
        return $this->render('syncReport.twig', array());
    }

    /**
     * @Route("/sync-report/json", name="syncReportJson", methods={"GET"})
     */
    public function viewSyncReportListJson(Request $request) {
        // set client
        // set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $start = $request->get('start');
        $length = $request->get('length');
        $draw = (int) $request->get('draw');
        $search = $request->get('search');
        $order = $request->get('order');
        $orderColumn = $order[0]['column'];
        $orderDirection = $order[0]['dir'];
        $areaNamesColumnCount = $request->get('areaNamesColumnCount');
        $dictionary = $request->get('dictionary');

        $headers['Authorization'] = $authHeader;
        $headers['Accept'] = 'application/json';
        $headers['x-csw-report-start'] = $start;
        $headers['x-csw-report-length'] = $length;
        $headers['x-csw-report-search'] = $search['value'];
        $headers['x-csw-report-order-column'] = $orderColumn;
        $headers['x-csw-report-order-direction'] = $orderDirection;
        $headers['x-csw-report-area-names-column-count'] = $areaNamesColumnCount;
        $headers['x-csw-report-dictionary'] = $dictionary;

        $dictionaryIdCount = 0;
        $dictionaryIds = $request->get('dictionaryIds');
        if ($dictionaryIds !== null) {
            foreach($dictionaryIds as $dictionaryId) {
                $dictionaryIdCount += 1;
                $key = 'x-csw-report-dictionary-id-' . $dictionaryIdCount;
                $headers[$key] = $dictionaryId;
            }
        }

        $headers['x-csw-report-dictionary-id-count'] = $dictionaryIdCount;

        // Each dictionary id corresponds to a column in the data table. Plus one more for the aggregate column.
        // There is one column filter for each column.
        $columnFilterCount = $dictionaryIdCount + 1;
        for ($i = 0; $i < $columnFilterCount; $i++) {
            $columnFilter = $request->get('columns')[$i]['search']['value'];
            $key = 'x-csw-report-column-filter-' . $i;
            $headers[$key] = $columnFilter;
        }

        $apiResponse = $this->client->request('GET', 'sync-report', null,  $headers);

        $headers = $apiResponse->getHeaders();
        $totalRowCount = 0;
        $filteredRowCount = 0;

        // Total row count
        if (array_key_exists('x-csw-report-count', $headers)) {
            $temp = $headers['x-csw-report-count'];
            $totalRowCount = $temp[0];
        } else
            $totalRowCount = 0;

        // Filter row count
        if (array_key_exists('x-csw-report-filtered', $headers)) {
            $temp = $headers['x-csw-report-filtered'];
            $filteredRowCount = $temp[0];
        } else
            $filteredRowCount = 0;

        $syncReportlist = json_decode($apiResponse->getBody());
        $result = array("data" => $syncReportlist, "draw" => $draw, "recordsTotal" => $totalRowCount, "recordsFiltered" => $filteredRowCount);
        $response = new Response(json_encode($result));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/deleteAreaNames", name="delete-area-names", methods={"DELETE"})
     */
    public function deleteAreaNames(Request $request) {
        // set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $apiResponse = $this->client->request('DELETE', 'delete-area-names', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
        ]);

        $deleteAreaNames = json_decode($apiResponse->getBody());
        $response = new Response(json_encode($deleteAreaNames));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/report-area-names-column-count/json", name="report-area-names-column-count-json", methods={"GET"})
     */
    public function viewReportAreaNamesColumnCountListJson(Request $request) {
        // set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $apiResponse = $this->client->request('GET', 'report-area-names-column-count', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
        ]);

        $reportDictionaryList = json_decode($apiResponse->getBody());
        $response = new Response(json_encode($reportDictionaryList));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/report-dictionaries/json", name="report-dictionaries-json", methods={"GET"})
     */
    public function viewReportDictionariesListJson(Request $request) {
        // set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $apiResponse = $this->client->request('GET', 'report-dictionaries', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
        ]);

        $reportDictionaryList = json_decode($apiResponse->getBody());
        $response = new Response(json_encode($reportDictionaryList));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/report-dictionary-ids/json", name="report-dictionary-ids-json", methods={"GET"})
     */
    public function viewReportDictionaryIdsListJson(Request $request) {
        // set the oauth token
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $dictionary = $request->get('dictionary');

        $apiResponse = $this->client->request('GET', 'report-dictionary-ids', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
            'x-csw-report-dictionary' => $dictionary
        ]);

        $reportDictionaryIdsList = json_decode($apiResponse->getBody());
        $response = new Response(json_encode($reportDictionaryIdsList));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/report-filter-dictionary-ids/json", name="report-filter-dictionary-ids-json", methods={"GET"})
     */
    public function getReportFilterIds(Request $request) {
        $dictionaryName = $request->get('dictionary');
        $ids = $request->get('ids');

        $this->logger->debug("Getting Id list for dictionary:  $dictionaryName");
        
        $idList = $this->mapDataRepository->getIdListForLevel($dictionaryName, $ids);

        $this->logger->debug(json_encode($idList));
        $response = new Response(json_encode($idList), 200);
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }
    
    /**
     * @Route("/report-view-case/json", name="report-view-case-json", methods={"GET"})
     */
    public function showCaseFromReport(Request $request) {
        $dictionaryName = $request->get('dictionary');
        $caseId = $request->get('caseguid');
        
        if ($caseId == null) {
            $ids = $request->get('ids');

            $this->logger->debug("Getting case GUID from the data for dictionary:  $dictionaryName");
            $caseId = $this->mapDataRepository->getCaseGuids($dictionaryName, $ids)[0]["case-id"];
        }
        
        $this->logger->debug($caseId);
        
        // Set the oauth token for api request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //upload dictionary
        $response = $this->client->request('GET', 'dictionaries/' . $dictionaryName . '/cases/' . $caseId, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        // Unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        try {
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

            $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);
            $caseJSON = $response->getBody();
            $caseJSON = json_decode($caseJSON, true);
            $caseHtml = $dictionaryHelper->formatCaseJSONtoHTML($dictionary, $caseJSON);

            $this->logger->debug('caseHTML: ' . $caseHtml);
            $response = new CSProResponse($caseHtml);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            $response->headers->set('Content-Type', 'text/html');
            $response->setCharset('utf-8');
        } catch (\Exception $e) {
            $this->logger->error('Failed getting case html', array("context" => (string) $e));
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting case html';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_case_html_error', $result ['description']);
        }

        return $response;
    }
    
    /**
     * @Route("/report-get-dupl-case-guids/json", name="report-get-dupl-case-guids-json", methods={"GET"})
     */
    public function getCaseGuidsForId(Request $request) {
        $dictionaryName = $request->get('dictionary');
        $ids = $request->get('ids');
        
        $this->logger->debug("Getting case GUIDs for all cases from the data for dictionary:  $dictionaryName");
        $caseIds = $this->mapDataRepository->getCaseGuids($dictionaryName, $ids);
        
        $this->logger->debug(json_encode($caseIds));
        $response = new Response(json_encode($caseIds), 200);
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }
}
