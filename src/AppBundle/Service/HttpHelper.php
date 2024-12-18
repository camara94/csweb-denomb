<?php

namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

// Fake Guzzle response class used
// when Guzzle exception doesn't have response
// so that clients can't treat the case as same
// as errors with response.
class HttpHelperErrorResponse {

    private $message;

    public function __construct($msg) {
        $this->message = $msg;
    }

    public function getStatusCode() {
        return 500;
    }

    public function getHeaders() {
        return array();
    }

    public function getHeader($h) {
        return '';
    }

    public function getBody() {
        return $this->message;
    }

}

// Helper class for making Guzzle http requests.
// Mostly handles correctly dealing with and logging
// Guzzle exceptions.
class HttpHelper {

    private $client;
    private $baseUri;
    private $logger;

    public function __construct(Client $guzzleClient, $baseUri, LoggerInterface $logger) {
        $this->client = $guzzleClient;
        $this->baseUri = $baseUri;
        $this->logger = $logger;
    }

    public function request($method, $path, $body, $headers) {
        $uri = $this->baseUri . $path;
        $response = null;
        try {
            $response = $this->client->request($method, $uri, ['body' => $body, 'headers' => $headers]);
        } catch (GuzzleHttp\Exception\RequestException $e) {
            if ($response->hasResponse())
                $response = $e->getResponse();
            else
                $response = new HttpHelperErrorResponse($e->getMessage());
            $this->logger->addError('RequestException calling: ' . $uri);
            $this->logger->addError($response->getBody());
        } catch (\Exception $e) {
            // Despite what Guzzle doc says, there are cases where
            // Guzzle throws an exception that is not derived
            // from RequestException.
            $response = new HttpHelperErrorResponse($e->getMessage());
            $this->logger->addError('Exception calling: ' . $uri);
            $this->logger->addError($e);
        }

        return $response;
    }

}
