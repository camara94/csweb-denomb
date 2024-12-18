<?php

namespace AppBundle\EventSubscriber;

use AppBundle\Controller\api\ApiTokenAuthenticatedController;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Psr\Log\LoggerInterface;
use AppBundle\CSPro\CSProResponse;
use AppBundle\Service\PdoHelper;
use AppBundle\Service\OAuthHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use AppBundle\CSPro\CSProSchemaValidator;
use AppBundle\Security\ApiKeyUserProvider;
use AppBundle\CSPro\User\User;

//checks the oauth token validatity before a request is handled.
class ApiTokenSubscriber implements EventSubscriberInterface {

    private $logger;
    private $pdo;
    private $oauthService;
    private $container;
    private $apikeyUserProvider;

    public function __construct(ContainerInterface $container, PdoHelper $pdo, OAuthHelper $oauthService, ApiKeyUserProvider $keyUserProvider, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
        $this->oauthService = $oauthService;
        $this->container = $container;
        $this->apikeyUserProvider = $keyUserProvider;

        // Set default timezone to avoid warnings when using date/time functions
        // and timezone not set in php.ini
        date_default_timezone_set($this->container->getParameter('csweb_api_default_timezone'));
    }

    public function verifyResourceRequest(Request $request) {
        // ...  
        //getContent becomes empty when doing verifyResourceRequest check in the before event  on PUT requests 
        //one way to avoid is to remove this check on PUT requests before events. 
        //alternative is to call the getContent  on PUT requests. If this sttops working remove the before event and figure out 
        //to authenticate the token in PUT methods  -https://forum.phalconphp.com/discussion/6422/sending-put-request-with-content-type-other-than-texthtml-fails
        //this bug is due to the call to verifyResourceRequest in  bshaefer's oauth package.
        if ($request->getMethod() == 'PUT')
            $content = $request->getContent();

        $this->logger->debug('Authenticating User.');

        //When running phpUnit tests the global $_SERVER headers are not set.  Adding them manually here to validate token
        $app_env = $this->container->get('kernel')->getEnvironment();
        $this->logger->debug('App Mode is: ' . $app_env);

        if ('test' == $app_env) {
            $_SERVER['HTTP_AUTHORIZATION'] = $request->headers->get('Authorization');
            $this->logger->debug('Authenticating User.' . $_SERVER['HTTP_AUTHORIZATION']);
        }

        if (!$this->oauthService->verifyResourceRequest(\OAuth2\Request::createFromGlobals())) {
            //$app['server']->getResponse()->send(); //send 401 response - Unauthorized;
            $response = new CSProResponse();
            $response->setError(401, 'Unauthorized', 'Unauthorized ');
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }

        //check if valid schema
        $schemaVersion = $this->container->getParameter('csweb_api_schema_version');
        $schemaValidator = new CSProSchemaValidator($schemaVersion, $this->pdo, $this->logger);
        if (!$schemaValidator->isValidSchema()) {
            $response = new CSProResponse();
            $msg = 'The database schema version does not match the version of the CSWeb code. Please run <a href="/upgrade/index.php">upgrade</a> to update the database to the latest version.';
            $response->setError(500, 'Internal Server Error', $msg);
            $response->headers->set('Content-Length', strlen($response->getContent()));
            return $response;
        }
        return null;
    }

    public function onKernelController(FilterControllerEvent $event) {
        $controller = $event->getController();
        $this->logger->debug("Event Subscriber: OnKernelController");
        /*
         * $controller passed can be either a class or a Closure.
         * This is not usual in Symfony but it may happen.
         * If it is a class, it comes in array format
         */
        if (!is_array($controller)) {
            return;
        }
        $request = $event->getRequest();
        $currentUrl = $request->getUri();
        $this->logger->debug('route is ' . $currentUrl);
        $strTokenRoute = '/token';
        $length = strlen($strTokenRoute);

        $isTokenRoute = (substr($currentUrl, -$length) === $strTokenRoute);

        if ($controller[0] instanceof ApiTokenAuthenticatedController) {
            //do the oauth check unless it is requesting a token
            if (!$isTokenRoute) {
                $response = $this->verifyResourceRequest($request);
                if ($response !== null) {
                    $event->setController(function()use($response) {
                        return $response;
                    });
                } else {
                    $apiKeyToken = $this->oauthService->getResourceController()->getToken();
                    $apiKey = $apiKeyToken['access_token'];
                    //set the token storage. 
                    $this->logger->debug("setting token storage " . $apiKey);
                    $user = $this->apikeyUserProvider->loadUserByApiKey($apiKey);
                   // $this->logger->debug("setting token storage" . print_r($user, true));
                    $roles = $user->getRoles();
                    $providerKey = 'cspro_oauth_provider';
                    $tokenStorage = new PreAuthenticatedToken(
                            $user, $apiKey, $providerKey, $roles
                    );
                    //set tokenstorage for authorization
                  //  $this->logger->debug("setting token storage" . print_r($tokenStorage, true));
                    $this->container->get('security.token_storage')->setToken($tokenStorage);
                }
            }
        }
    }

    public static function getSubscribedEvents() {
        return array(
            KernelEvents::CONTROLLER => 'onKernelController',
        );
    }

}
