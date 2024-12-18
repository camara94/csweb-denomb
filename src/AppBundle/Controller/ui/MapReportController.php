<?php

namespace AppBundle\Controller\ui;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\Data\MapDataRepository;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\DictionaryHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\Data\DataSettings;

class MapReportController extends Controller implements TokenAuthenticatedController {

    private $client;
    private $logger;
    private $pdo;
    private $mapDataRepository;

    public function __construct(HttpHelper $client, PdoHelper $pdo, LoggerInterface $logger) {
        $this->client = $client;
        $this->logger = $logger;
        $this->pdo = $pdo;
    }

    //override the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null) {
        parent::setContainer($container);
        $this->mapDataRepository = new MapDataRepository($this->pdo, $this->logger);
    }

    /**
     * @Route("/map-report", name="map-report", methods={"GET"})
     */
    public function viewMapReportListAction(Request $request) {
        return $this->render('mapReport.twig', array());
    }

    /**
     * @Route("/map-report/dictionary/ids", name="map_report_dictionary_ids", methods={"GET"})
     */
    public function getMapReportIds(Request $request) {

        $dictionaryName = $request->get('dictionary');
        $ids = $request->get('ids');

        $idList = $this->mapDataRepository->getIdList($dictionaryName, $ids);
        
        $response = new Response(json_encode($idList), 200);
        $response->headers->set('Content-Length', strlen($response->getContent()));

        return $response;
    }

    /**
     * @Route("/map-report/points", name="map_report_points", methods={"GET"})
     */
    public function getMapReportPoints(Request $request) {

        $dictionaryName = $request->get('dictionary');

        $ids = $request->get('ids');
        $maxMapPoints = $this->container->getParameter('csweb_max_map_points');
        $mapPoints = $this->mapDataRepository->getMapDataPoints($dictionaryName, $ids, $maxMapPoints);

        $response = new Response(json_encode($mapPoints), 200);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/map-report/dictionaries/", name="map_report_dictionaries", methods={"GET"})
     */
    public function getMapReportDictionariesList(Request $request) {
        // set the oauth token for api endpoint request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        // set authorization header
        $authHeader = 'Bearer ' . $access_token;

        $apiResponse = $this->client->request('GET', 'report-dictionaries', null, ['Authorization' => $authHeader,
            'Accept' => 'application/json',
        ]);

        $reportDictionaryList = json_decode($apiResponse->getBody(), true);

        $dataSettings = new DataSettings($this->pdo, $this->logger);
        $mapDataSettings = $dataSettings->getDataSettings();
        $mapReportDictionaryList = array();
        foreach ($mapDataSettings as $dataSetting) {
            //if map is enabled
            $dataSetting['mapInfo'] = json_decode($dataSetting['mapInfo'], true);
            if (isset($dataSetting['mapInfo']) && isset($dataSetting['mapInfo']['enabled']) && $dataSetting['mapInfo']['enabled'] === true) {
                $key = array_search($dataSetting['name'], array_column($reportDictionaryList, 'dictionary_name'));
                $reportDictionaryList[$key]['dataSetting'] = $dataSetting;
                $mapReportDictionaryList[] = $reportDictionaryList[$key];
            }
        }

        $response = new Response(json_encode($mapReportDictionaryList));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    /**
     * @Route("/map-report/marker/{dictName}/cases/{caseId}", methods={"GET"})
     */
    function getCaseMarker(Request $request, $dictName, $caseId) {
        // Set the oauth token for api endpoint request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //download case JSON
        $response = $this->client->request('GET', 'dictionaries/' . $dictName . '/cases/' . $caseId, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        // Unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        try {
            $markerItemList = $this->mapDataRepository->getCaseMarkerItemList($dictName);
            $mapMarkerInfo = array();
            if (!empty($markerItemList)) {

                $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
                $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
                $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

                $dictionary = $dictionaryHelper->loadDictionary($dictName);
                $caseJSON = $response->getBody();
                $caseJSON = json_decode($caseJSON, true);
                $mapMarkerInfo = $dictionaryHelper->formatMapMarkerInfo($dictionary, $caseJSON, $markerItemList);
            }
            $response = new Response(json_encode($mapMarkerInfo), 200);
            $response->headers->set('Content-Length', strlen($response->getContent()));

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Failed getting case marker info', array("context" => (string) $e));
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting case marker info';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_case_marker_info_error', $result ['description']);
        }


        return $response;
    }

    /**
     * @Route("/map-report/{dictName}/cases/{caseId}", methods={"GET"})
     */
    function getCase(Request $request, $dictName, $caseId) {
        // Set the oauth token for api request
        $access_token = $request->cookies->has('access_token') ? $request->cookies->get('access_token') : "";
        $authHeader = 'Bearer ' . $access_token;

        //upload dictionary
        $response = $this->client->request('GET', 'dictionaries/' . $dictName . '/cases/' . $caseId, null, ['Authorization' => $authHeader, 'Accept' => 'application/json']);

        // Unauthorized or expired redirect to logout page
        if ($response->getStatusCode() == 401) {
            return $this->redirectToRoute('logout');
        }

        try {
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

            $dictionary = $dictionaryHelper->loadDictionary($dictName);
            $caseJSON = $response->getBody();
            $caseJSON = json_decode($caseJSON, true);
            $caseHtml = $dictionaryHelper->formatCaseJSONtoHTML($dictionary, $caseJSON);

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
     * @Route("/map-report/location-items/{dictionaryName}",name="report_dictionary_location", methods={"GET"})
     */
    function getDictionaryLatLongList(Request $request, $dictionaryName) {
        try {
            $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
            $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
            $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);

            $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);
            $result = array();
            $result['gps'] = $dictionaryHelper->getPossibleLatLongItemList($dictionary);
            $result['metadata'] = $dictionaryHelper->getItemsForMapPopupDisplay($dictionary);

            $response = new CSProResponse(json_encode($result), 200);
        } catch (\Exception $e) {
            $this->logger->error('Failed getting report latitude and longitude item list', array("context" => (string) $e));
            $result ['code'] = 500;
            $result ['description'] = 'Failed getting report latitude and longitude item list';
            $response = new CSProResponse();
            $response->setError($result ['code'], 'map_report_get_lat_long_error', $result ['description']);
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
