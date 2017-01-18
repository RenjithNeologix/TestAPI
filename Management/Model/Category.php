<?php

namespace Erply\Management\Model;

use Erply\Management\Model\Config\Config;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Model\StoreManagerInterface;

class Category {

    protected $_config;
    protected $_objectManager;
    protected $_storeManager;
    protected $_categoryFactory;
    protected $_record;
    protected $_categories;
    protected $_pageNo;
    protected $_updatepageNo;
    protected $_isNew;
    protected $_erplyPageNo;

    public function __construct(Config $config, CategoryFactory $categoryFactory, StoreManagerInterface $storeManager) {

        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_categoryFactory = $categoryFactory;
        $this->_storeManager = $storeManager;
        $this->_config = $config;
        $this->_pageNo = 1;
        $this->_updatepageNo = 1;
        $this->_isNew = 0;
        $this->_erplyPageNo = 1;
    }

    public function lastSync() {

        $data = $this->_config->getLastSync('cron_erply_category');

        if ($this->_pageNo == 1) {
            $erplyCategories = array();
            $this->getAllErplyCategories($erplyCategories);
        }

        if ($data) {
            return $data;
        }

        return false;
    }

    public function create() {

        $changedSince = "";
        if ($this->lastSync() > 2)
            $changedSince = strtotime('-1 day');

        echo "create...";
        //$data = $this->_config->request("getProductCategories", array('changedSince'=> $changedSince, 'recordsOnPage'=>100, 'pageNo'=> $this->_pageNo));
        $data = $this->_config->request("getProductCategories", array('recordsOnPage' => 100, 'pageNo' => $this->_pageNo));

        if (empty($data['records'])) {
            $this->_pageNo = 1;
            return true;
        }

        $records = $this->array_orderby($data['records'], 'parentCategoryID', SORT_ASC);
        $this->_categories = $records;

        foreach ($records as $value) {

            $name = strtoupper($value['productCategoryName']);
            $name = str_replace("&AMP;", "&", trim($name));

            $collection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('name', $name)->getFirstItem();
            $erplyCollection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('erply_category_id', $value['productCategoryID'])->getFirstItem();

            if ($erplyCollection->getData()) {
                //echo "OLD";
                $this->_record = $value;
                $this->updateCategories($erplyCollection->getEntityId());
            }

            if (!$erplyCollection->getData()) {
                //echo "NEW";
                $this->_isNew = 1;
                $this->_record = $value;
                $this->importCategories();
            }
        }

        if (count($records) < 100) {
            if ($this->_isNew == 1) {
                $this->update();
            }
            exit(); //All categories are imported.
        }

        $this->_pageNo++;   //Page Number increment.
        $this->create(); //Run for other pages.
    }

    public function update() {

        $changedSince = "";
        if ($this->lastSync() > 2)
            $changedSince = strtotime('-1 day');
        // $data = $this->_config->request("getProductCategories", array('changedSince'=> $changedSince, 'recordsOnPage'=>100, 'pageNo'=> $this->_updatepageNo));
        $data = $this->_config->request("getProductCategories", array('recordsOnPage' => 100, 'pageNo' => $this->_updatepageNo));

        if (empty($data['records'])) {
            //$this->_logger->info("No new record categories");
            return true;
            exit;
        }
        //echo "Page number: ".$this->_pageNo.' and Count '.count($data['records']).'<br>';
        $records = $this->array_orderby($data['records'], 'parentCategoryID', SORT_ASC);
        foreach ($records as $value) {

            $erplyCollection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('erply_category_id', $value['productCategoryID'])->getFirstItem();

            if ($erplyCollection->getData()) {
                //echo "OLD";
                //$this->_logger->info("OLD".'--'.count($records).'--'.$this->_record['parentCategoryID']);
                $this->_record = $value;
                $this->updateCategories($erplyCollection->getEntityId());
            }
        }
        if (count($records) < 100) {
            exit(); //All categories are imported.
        }

        $this->_updatepageNo++;   //Page Number increment.
        $this->update(); //Run for other pages.
    }

    public function removeCategories($erplyCategories) {

        $categoryFactory = $this->_objectManager->create('Magento\Catalog\Model\ResourceModel\Category\CollectionFactory');
        $categories = $categoryFactory->create()
                ->addAttributeToSelect('*')
                ->addAttributeToFilter('erply_category_id', array('neq' => "")); //categories from current store will be fetched

        $registry = $this->_objectManager->get('Magento\Framework\Registry');
        foreach ($categories as $category) {

            if (array_key_exists($category->getErplyCategoryId(), $erplyCategories)) {
                continue;
            } else {
                echo "Removed: [".$category->getErplyCategoryId()."] = [".$category->getErplyCategoryId()."]<br>";
                $registry->unregister('isSecureArea');
                $registry->register('isSecureArea', true);
                $category->delete();
                $registry->unregister('isSecureArea');
            }
        }
        //echo "<pre>"; print_r($erplyCategories); //die('hre');
        return true;
    }

    public function getAllErplyCategories($erplyCategories) {

        $data = $this->_config->request("getProductCategories", array('recordsOnPage' => 100, 'pageNo' => $this->_erplyPageNo));

        if (empty($data['records'])) {
            $this->removeCategories($erplyCategories); //Remove all the 
            //$this->_logger->info("No Erply category found.");
            return true;
        }
        //echo "Page number: ".$this->_pageNo.' and Count '.count($data['records']).'<br>';
        $records = $this->array_orderby($data['records'], 'parentCategoryID', SORT_ASC);
        foreach ($records as $value) {

            $erplyCategories[$value['productCategoryID']] = $value['productCategoryID'];
        }

        if (count($records) < 100) {
            $this->removeCategories($erplyCategories); //Remove all the 
            return true;
        }

        $this->_erplyPageNo++;   //Page Number increment.
        $this->getAllErplyCategories($erplyCategories); //Run for other pages.
    }

    public function importCategories() {

        if ($this->_record) {

            if ($this->_record['parentCategoryID'] > 0) {
                return $this->getParentCategory($this->_record, $this->_record['parentCategoryID']);
            }
            // Get Store ID
            $store = $this->_storeManager->getStore();
            $storeId = $store->getStoreId();
            // Get Root Category ID
            $rootNodeId = 2; //$store->getRootCategoryId();
            // Get Root Category
            $rootCat = $this->_objectManager->get('Magento\Catalog\Model\Category');
            $rootCat->load($rootNodeId);

            $name = strtoupper($this->_record['productCategoryName']);
            $name = str_replace("&AMP;", "&", trim($name));

            // Add a new sub category under root category
            $categoryTmp = $this->_categoryFactory->create();
            $categoryTmp->setName($name);
            $categoryTmp->setIsActive(true);
            $categoryTmp->setParentId($rootNodeId);
            $categoryTmp->setStoreId($storeId);
            $categoryTmp->setErplyCategoryId($this->_record['productCategoryID']);
            $categoryTmp->setPath($rootCat->getPath());
            $categoryTmp->setUrlKey($this->_record['productCategoryID'] . '-' . strtolower($name));
            $categoryTmp->save();
        }
        return true;
    }

    public function getParentCategory($record, $parentCategoryID) {

        //$key = array_keys(array_column($this->_categories, 'productCategoryID'), $parentCategoryID);
        $data = $this->_config->request("getProductCategories", array('productCategoryID' => $parentCategoryID, 'recordsOnPage' => 1));

        if (empty($data['records'])) {
            echo "No Parent Category Found.";
            return true;
        }
        $ErplyParentCategory = $data['records'][0]; //$this->_categories[$key[0]];
        // Get Store ID
        $store = $this->_storeManager->getStore();
        $storeId = $store->getStoreId();
        $parentNodeId = $this->getCategoryId($ErplyParentCategory['productCategoryID']);
        // Get Parent Category ID
        if (!$parentNodeId) {
            $parentNodeId = $store->getRootCategoryId(); // Get Root Category ID
        }

        $collection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('erply_category_id', $this->_record['productCategoryID'])->getFirstItem();
        if (!$collection->getData()) {

            $name = strtoupper($this->_record['productCategoryName']);
            $name = str_replace("&AMP;", "&", trim($name));


            // Get Root Category
            $parentCat = $this->_objectManager->get('Magento\Catalog\Model\Category');
            $parentCat->load($parentNodeId);
            // Add a new sub category under root category
            $categoryTmp = $this->_categoryFactory->create();
            $categoryTmp->setName($name);
            $categoryTmp->setIsActive(true);
            $categoryTmp->setParentId($parentNodeId);
            $categoryTmp->setErplyCategoryId($this->_record['productCategoryID']);
            $categoryTmp->setStoreId($storeId);
            $categoryTmp->setPath($parentCat->getPath());
            $categoryTmp->setUrlKey($this->_record['productCategoryID'] . '-' . strtolower($name));
            $categoryTmp->save();
        }
    }

    public function updateCategories($categoryId) {

        // Get Store ID
        $store = $this->_storeManager->getStore();
        $storeId = $store->getStoreId();

        $name = strtoupper($this->_record['productCategoryName']);
        $name = str_replace("&AMP;", "&", trim($name));

        // Update category
        $categoryTmp = $this->_categoryFactory->create()->load($categoryId);
        if ($categoryTmp->getName() !== $name) {
            $categoryTmp->setName($name);
        }
        $categoryTmp->setStoreId($storeId);
        if ($this->_record['parentCategoryID'] > 0) {
            $collection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('erply_category_id', $this->_record['parentCategoryID'])->getFirstItem();
            if ($collection->getData()) {
                $categoryTmp->setParentId($collection->getEntityId());
                echo $collection->getEntityId() . '--' . $collection->getPath() . '--<br>';
                $categoryTmp->setPath($collection->getPath() . '/' . $categoryId);
            }
        }
        $categoryTmp->save();
    }

    public function getCategoryId($erply_category_id) {
        $collection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('erply_category_id', $erply_category_id)->getFirstItem();
        if ($collection->getData()) {
            return $collection->getEntityId();
        }
        return false;
    }

    public function array_orderby() {
        $args = func_get_args();
        $data = array_shift($args);
        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row)
                    $tmp[$key] = $row[$field];
                $args[$n] = $tmp;
            }
        }
        $args[] = &$data;
        call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }

}
