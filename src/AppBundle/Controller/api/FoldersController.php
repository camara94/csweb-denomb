<?php

namespace AppBundle\Controller\api;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\CSPro\FileManager;
use Psr\Log\LoggerInterface;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use AppBundle\CSPro\CSProResponse;

class FoldersController extends Controller implements ApiTokenAuthenticatedController {

    private $logger;
    private $pdo;
    private $oauthService;

    public function __construct(OAuthHelper $oauthService, PdoHelper $pdo, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->oauthService = $oauthService;
    }

    /**
     * @Route("/folders/{folderPath}", methods={"GET"}, requirements={"folderPath"=".*"})
     */
    function getDirectoryListing(Request $request, $folderPath) {
        $fileManager = new FileManager();
        $fileManager->rootFolder = $this->container->getParameter('csweb_api_files_folder');
        $dirList = $fileManager->getDirectoryListing($folderPath);
        $response = null;
        if (is_dir($fileManager->rootFolder . DIRECTORY_SEPARATOR . $folderPath)) {
            $response = new CSProResponse(json_encode($dirList));
        } else {
            $response = new CSProResponse();
            $response->setError(404, 'directory_not_found', 'Directory not found');
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
