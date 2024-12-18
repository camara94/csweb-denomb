<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\DBConfigSettings;

class ApiController extends Controller implements ApiTokenAuthenticatedController {

    private $logger;
    private $pdo;
    private $oauthService;

    public function __construct(OAuthHelper $oauthService, PdoHelper $pdo,  LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->oauthService = $oauthService;
    }
    /**
     * @Route("/token", methods={"POST"})
     */
    public function getTokenAction(Request $request) {
        // Handle a request for an OAuth2.0 Access Token and send the response to the client
        //TODO: When running phpUnit tests the global $_SERVER headers are not set.  Adding them manually here to get  token
         $this->logger->debug('processing getToken request');
        $app_env = $this->container->get('kernel')->getEnvironment();
        if ('test' == $app_env) {
            if (!isset($_SERVER['REQUEST_METHOD'])) {
                $_SERVER['REQUEST_METHOD'] = $request->getMethod();
            }
            if (!isset($_SERVER['CONTENT_TYPE'])) {
                $_SERVER['CONTENT_TYPE'] = 'application/json';
            }
        }

        $oauthRequest = \OAuth2\Request::createFromGlobals();

        //When running phpUnit tests the $_POST or php.input is not set. Setting the JSON body here to get  token
        if ('test' == $app_env) {
            $oauthRequest->request = json_decode($request->getContent(), true);
        }

        $oauthResponse = $this->oauthService->handleTokenRequest($oauthRequest);

        if ($oauthResponse->isSuccessful()) {
            // Success
            $response = new CSProResponse($oauthResponse->getResponseBody(), $oauthResponse->getStatusCode(), $oauthResponse->getHttpHeaders());
        } else {
            // Translate the oauth error into CSPro error format
            $response = new CSProResponse();
            $oauthError = json_decode($oauthResponse->getResponseBody(), true);
            $response->setError($oauthResponse->getStatusCode(), $oauthError['error'], $oauthError['error_description']);
            $response->headers->add($oauthResponse->getHttpHeaders());
        }

        $response->headers->set('Content-Length', strlen($response->getContent()));
        $this->logger->debug($response->getContent());
        return $response;
    }

    /**
     * @Route("/server", name="server", methods={"GET"})
     */
    public function getServerAction(Request $request) {
        
        $dbConfigSettings= new DBConfigSettings($this->pdo, $this->logger);
        $result['deviceId'] = $dbConfigSettings->getServerDeviceId(); //server name
        $result['apiVersion'] = $this->container->getParameter('csweb_api_version');
        $response = new CSProResponse(json_encode($result));
        //remove quotes around quoted numeric values
        $response->setContent(preg_replace('/"(-?\d+\.?\d*)"/', '$1', $response->getContent()));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        //echo ' total time'.(microtime(true)-$app['start']);
        return $response;
    }

}
