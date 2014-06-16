<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use OpsWay\Api\ChefServer;

class IndexController extends AbstractActionController
{
    protected $_config = null;
    protected $_cacheSettings = array();

    public function __construct()
    {
        $this->getEventManager()->attach('dispatch', function ($e) {
            $controller = $e->getTarget(); // this will return your controller instance
            $controller->init();
        },50);
    }

    protected function init(){
        $this->_config = $this->getServiceLocator()->get('Config');
        if (!isset($this->_config['settings'])) {
            throw new \ErrorException('Do not set application settings');
        }
        $this->_config = $this->_config['settings'];

        foreach ($this->_config as $scope => $value){
            $this->_cacheSettings[$scope] = array(
                'repo' => PRIVATE_DATA_FOLDER . 'cache' . DIRECTORY_SEPARATOR . $scope . '-repo-data.cache',
                'chef' => PRIVATE_DATA_FOLDER . 'cache' . DIRECTORY_SEPARATOR . $scope . '-chef-data.cache'
            );
        }
        $this->_cacheSettings['nodes'] = PRIVATE_DATA_FOLDER . 'cache' . DIRECTORY_SEPARATOR . 'nodes-chef-data.cache';
    }

    protected function getConfig(){
        return $this->_config;
    }

    public function getkeyAction(){
        $key = $this->params()->fromQuery('key',0);
        $config = $this->getConfig();
        $ssh_key = 'Not found';
        foreach ($config as $scope => $settings){
            $repoUsers = @unserialize(@file_get_contents($this->_cacheSettings[$scope]['repo']));
            $chefUsers = @unserialize(@file_get_contents($this->_cacheSettings[$scope]['chef']));
            if (isset($chefUsers[$key])){
                $ssh_key = $chefUsers[$key]['ssh_key'];
                break;
            }
            if (isset($repoUsers[$key])){
                $ssh_key = $repoUsers[$key]['ssh_key'];
                break;
            }
        }
       return $this->getResponse()->setContent($ssh_key);
    }

    public function indexAction()
    {
        $filterByNode = $this->params()->fromPost('node',null);
        $config = $this->getConfig();
        $tabsInfo = array();
        foreach ($config as $scope => $settings){
            $repoUsers = @unserialize(@file_get_contents($this->_cacheSettings[$scope]['repo']));
            $chefUsers = @unserialize(@file_get_contents($this->_cacheSettings[$scope]['chef']));
            if (count($repoUsers) == 0 || count($chefUsers) == 0) {
                $this->flashMessenger()->addMessage('No cache data. Please click update button for fetch new data.');
                return new ViewModel();
            }
            $tabsInfo[$scope] = array();
            $repoUsersUnprocessed = $repoUsers;
            foreach ($chefUsers as $key => $user){
                if (!is_null($filterByNode)){
                    if (!$user['systemteam']){
                        $match = false;
                          foreach ($user['nodes'] as $node) {
                              if (stripos($node,$filterByNode) !== FALSE) $match = true;
                              if (stripos($filterByNode,$node) !== FALSE) $match = true;
                          }
                        if (!$match) continue;
                    }
                }
                $tabsInfo[$scope][$key] = $user;

                $errors = array();
                if (isset($repoUsers[$key])) {
                    if ($repoUsers[$key]['email'] != $chefUsers[$key]['email']) {
                        $errors[] = 'Does not compare between GIT and CHEF.';
                    }
                } else {
                    $errors[] = 'Does not exists user in GIT repo.';
                }

                $validator = new \Zend\Validator\EmailAddress();
                if (!$validator->isValid($chefUsers[$key]['email'])) {
                    $errors[] = "Email is not correct.";
                }

                //@todo Add more validation

                $tabsInfo[$scope][$key]['validate'] = $errors;
                unset($repoUsersUnprocessed[$key]);
            }

            foreach ($repoUsersUnprocessed as $key => $user){
                if (!is_null($filterByNode)){
                    if (!$user['systemteam']){
                        $match = false;
                          foreach ($user['nodes'] as $node) {
                              if (stripos($node,$filterByNode) !== FALSE) $match = true;
                              if (stripos($filterByNode,$node) !== FALSE) $match = true;
                          }
                        if (!$match) continue;
                    }
                }
                $tabsInfo[$scope][$key] = $user;
                $errors = array();
                $errors[] = 'Does not exists user in CHEF.';

                $tabsInfo[$scope][$key]['validate'] = $errors;
            }

        }

        $nodeList = @unserialize(@file_get_contents($this->_cacheSettings['nodes']));
        if (!$nodeList) $nodeList = array();
         return new ViewModel(array('tabs'=>$tabsInfo,'nodeList'=>$nodeList));
    }

    public function testAction(){
        $nodeList = array();
        foreach ($this->getConfig() as $scope => $settings){
            $api = new ChefServer($settings['chef']['url'],$settings['chef']['endpoint'],$settings['chef']['client_name'], $settings['chef']['client_key']);
            $nodeList = $nodeList + array_keys($api->get('/nodes'));
        }
        return $this->getResponse()->setContent(print_r($nodeList,true));
    }

    public function updateAction(){

        $error = false;
        try {
            foreach ($this->getConfig() as $scope => $settings){
                $api = new ChefServer($settings['chef']['url'],$settings['chef']['endpoint'],$settings['chef']['client_name'], $settings['chef']['client_key']);
                $chefUserKeys = array_keys($api->get('/data/users') );
                $chefUsers = array();
                foreach ($chefUserKeys as $valueKey){
                    $row = $api->get('/data/users/'.$valueKey);
                    $chefUsers[$valueKey] = $row;//array('')
                    $key = explode(' ',$row['ssh_key']);
                    $chefUsers[$valueKey]['email'] = array_pop($key);
                    $chefUsers[$valueKey]['ssh_key'] = implode(' ',$key);
                }

                file_put_contents($this->_cacheSettings[$scope]['chef'],serialize($chefUsers));
                if (count($chefUsers) == 0 || filesize($this->_cacheSettings[$scope]['chef']) < 100) {
                    throw new \Exception('Can not fetch and save chef data via api.');
                }

                $repo_folder = $settings['repo']['folder'];
                if (!file_exists($repo_folder)){
                    @mkdir($repo_folder);
                }

                if (iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repo_folder,\FilesystemIterator::SKIP_DOTS))) <= 1){
                    exec("git clone https://{$settings['repo']['user']}:{$settings['repo']['password']}@{$settings['repo']['url']} $repo_folder");
                }
                /*
                exec("cd $repo_folder; git pull"); */

                $userFolder = $repo_folder . DIRECTORY_SEPARATOR . 'data_bags' . DIRECTORY_SEPARATOR . 'users';
                $repoUsers = array();
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($userFolder,\FilesystemIterator::SKIP_DOTS)) as $val) {
                    /**
                    * @var $val SplFileInfo
                    */
                    $fileKeyPath = $val->getPathName();
                    $fileKeyName = $val->getFilename();
                    $key = str_replace('.json','',$fileKeyName);
                    $row = json_decode(file_get_contents($fileKeyPath),true);
                    $repoUsers[$key] = $row;
                    $repoUsers[$key]['org_name'] = str_replace(array($userFolder,$fileKeyName),'',$fileKeyPath);
                    $keyString = explode(' ',$row['ssh_key']);
                    $repoUsers[$key]['email'] = array_pop($keyString);
                    $repoUsers[$key]['ssh_key'] = implode(' ',$keyString);
                }

                file_put_contents($this->_cacheSettings[$scope]['repo'],serialize($repoUsers));

                if (count($repoUsers) == 0 || filesize($this->_cacheSettings[$scope]['repo']) < 100) {
                    throw new \Exception('Can not fetch and save git repo data.');
                }
            }

            $nodeList = array();
            foreach ($this->getConfig() as $scope => $settings){
                $api = new ChefServer($settings['chef']['url'],$settings['chef']['endpoint'],$settings['chef']['client_name'], $settings['chef']['client_key']);
                $nodeList = array_merge($nodeList + array_keys($api->get('/nodes')));
            }

            file_put_contents($this->_cacheSettings['nodes'],serialize($nodeList));

        } catch (\Exception $e){
            $error = true;
            $this->flashMessenger()->addErrorMessage('Error during update: '.$e->getMessage());
        }

        if (!$error) $this->flashMessenger()->addSuccessMessage('Cache data was updated successfully.');
        return $this->redirect()->toRoute('home');
    }
}
