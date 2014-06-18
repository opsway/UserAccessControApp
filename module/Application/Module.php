<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Application;

use Zend\Mvc\ModuleRouteListener;
use Zend\Mvc\MvcEvent;

class Module
{
    protected $whitelist = array('zfcuser/login');

    public function onBootstrap(MvcEvent $e)
    {
        $eventManager        = $e->getApplication()->getEventManager();
        $moduleRouteListener = new ModuleRouteListener();
        $moduleRouteListener->attach($eventManager);

        $app = $e->getApplication();
            $sm = $app->getServiceManager();
            $app->getEventManager()->attach(
                'route',
                function($e) {
                    $app = $e->getApplication();
                    $routeMatch = $e->getRouteMatch();
                    $sm = $app->getServiceManager();
                    $auth = $sm->get('zfcuser_auth_service');
                    if (!$auth->hasIdentity() && $routeMatch->getMatchedRouteName() != 'zfcuser/login') {
                        $response = $e->getResponse();
                        $response->getHeaders()->addHeaderLine(
                            'Location',
                            $e->getRouter()->assemble(
                                    array(),
                                    array('name' => 'zfcuser/login')
                            )
                        );
                        $response->setStatusCode(302);
                        return $response;
                    }
                },
                -100
            );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                    'OpsWay' => './vendor/OpsWay'
                ),
            ),
        );
    }
}
