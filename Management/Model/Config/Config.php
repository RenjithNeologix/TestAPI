<?php

namespace Erply\Management\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Erply\Management\Model\Config\EAPI;

class Config {

    protected $_resultPageFactory;
    protected $_scopeConfig;
    protected $_encryptor;
    protected $_api;
    protected $connection;
    protected $_resource;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor) {

        $this->_api = new EAPI();
        $this->_scopeConfig = $scopeConfig;
        $this->_encryptor = $encryptor;
        $this->_resources = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\App\ResourceConnection');
    }

    public function config() {
        // EAPI parameters
        $this->_api->clientCode = $this->_scopeConfig->getValue('management/mainpage/clientCode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->_api->username = $this->_scopeConfig->getValue('management/mainpage/username', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->_api->password = $this->_scopeConfig->getValue('management/mainpage/password', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $this->_api->password = $this->_encryptor->decrypt($this->_api->password);
        $this->_api->url = "https://" . $this->_api->clientCode . ".erply.com/api/";
    }

    public function request($type, $params = array()) {
        // Config EAPI parameters
        $this->config();

        // No input parameters are needed
        $result = $this->_api->sendRequest($type, $params);

        // Default output format is JSON, so we'll decode it into a PHP array
        $output = json_decode($result, true);
        if (empty($output['records'])) {
            echo "<pre>";
            print_r($output);
            echo "</pre>";
            //die('No record imported');
        }

        return $output;
    }

    protected function getConnection()
    {
        if (!$this->connection) {
            $this->connection = $this->_resources->getConnection('core_write');
        }
        return $this->connection;
    }
    
    public function getLastSync($code) {

        $table= $this->_resources->getTableName('cron_schedule'); 
        $data = $this->getConnection()->fetchRow('SELECT COUNT(*) FROM ' . $table . ' WHERE job_code = "' . $code . '" AND executed_at != "" ORDER BY schedule_id DESC;');
        
        if(!empty($data)) {
            return $data['COUNT(*)'];
        }
        return false;
    }
}

?>