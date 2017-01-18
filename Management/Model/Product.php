<?php

namespace Erply\Management\Model;

use Erply\Management\Model\Config\Config;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Setup\CategorySetup;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Erply\Management\Helper\Data;
use Magento\Store\Model\StoreManagerInterface;

class Product {

    protected $_config;
    protected $_objectManager;
    protected $_simpleProducts;
    protected $_directoryList;
    protected $_configurableProducts;
    protected $_associated = array();
    protected $_productFactory;
    protected $_categoryFactory;
    protected $_brands;
    protected $_warehouseId;
    protected $_eavSetupFactory;
    protected $_attributeFactory;
    protected $_helper;
    protected $_products;
    protected $_resources;
    protected $_matrixpageNo;
    protected $_simplepageNo;
    protected $_updatepageNo;
    protected $_storeManager;

    public function __construct(Config $config, DirectoryList $directoryList, ProductFactory $productFactory, 

CategoryFactory $categoryFactory,
            EavSetupFactory $eavSetupFactory, Attribute $attributeFactory, Data $data, StoreManagerInterface 

$storeManager) 
    {
        $this->_directoryList = $directoryList;
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_productFactory = $productFactory;
        $this->_categoryFactory = $categoryFactory;
        $this->_config = $config;
        $this->_eavSetupFactory = $eavSetupFactory;
        $this->_attributeFactory = $attributeFactory;
        $this->_storeManager = $storeManager;
        $this->_helper = $data;
        $this->_resources = $this->_objectManager->get('Magento\Framework\App\ResourceConnection');
        $this->_matrixpageNo = 1;
        $this->_simplepageNo = 1;
        $this->_updatepageNo = 1;
    }

    public function lastSync() {

        $data = $this->_config->getLastSync('cron_erply_product');
        
        if($data) {
            return $data;
        }
        
        return false;
    }
    
    public function create() {

        $changedSince = "";
        if($this->lastSync() > 2)
            $changedSince = strtotime('-1 day');

       $data = $this->_config->request("getProducts", array('type' => 'MATRIX', 'status'=>'ACTIVE', 'changedSince'=> $changedSince, 'recordsOnPage'=> 50, 'pageNo'=>$this->_matrixpageNo));
        //$data = $this->_config->request("getProducts", array('type' => 'MATRIX', 'status'=>'ACTIVE', 'recordsOnPage'=> 50, 'pageNo'=> $this->_matrixpageNo));

        $records = $data['records'];
        echo "Page number: ".$this->_matrixpageNo.' and Count '.count($data['records']).'<br>';
        foreach ($records as $value) {
            if (array_key_exists("productVariations", $value) && !empty($value['productVariations'])) {

                $this->_simpleProducts = $this->_config->request("getProducts", array('parentProductID' => $value['productID'], 'type' => 'PRODUCT', 'status'=>'ACTIVE', 'changedSince'=> $changedSince, 'recordsOnPage'=> 1000)); // 'productID'=>2
				
				//$this->_simpleProducts = $this->_config->request("getProducts", array('parentProductID' => $value['productID'], 'type' => 'PRODUCT', 'status'=>'ACTIVE', 'recordsOnPage'=> 1000)); // 'productID'=>2
				
                $this->importSimpleProducts();
                $this->_configurableProducts = $value;
                $this->importConfigurableProducts();
            }
        }
        
        if(count($records) < 50) {
            echo "All the matrix products are imported with simple products.<br>";
            $this->createSimple($changedSince);         //Create simple products from Erply.
        }

        $this->_matrixpageNo++;   //Page Number increment.
        $this->create(); //Run for other pages.
    }

    public function createSimple($changedSince) {

        $OtherData = $this->_config->request("getProducts", array('type' => 'PRODUCT', 'parentProductID' => "", 'status'=>'ACTIVE', 'changedSince'=> $changedSince, 'recordsOnPage'=> 50, 'pageNo'=> $this->_simplepageNo));

		//$OtherData = $this->_config->request("getProducts", array('type' => 'PRODUCT', 'parentProductID' => "", 'status'=>'ACTIVE', 'recordsOnPage'=> 50, 'pageNo'=> $this->_simplepageNo));

        $this->_simpleProducts = $OtherData;
        $this->importSimpleProducts();

        echo "Page number: ".$this->_simplepageNo.' and Count '.count($this->_simpleProducts['records']).'<br>';
        if(count($this->_simpleProducts['records']) < 50) {
            echo "Simple products are created without configurable.<br>";
            $this->updateAllProducts($changedSince);    //Update all products from Erply.
        }
        
        $this->_simplepageNo++;   //Page Number increment.
        $this->createSimple($changedSince); //Run for other pages.
    }

    public function updateAllProducts($changedSince) {
        
        $disabledData = $this->_config->request("getProducts", array('changedSince'=> $changedSince, 'recordsOnPage'=> 50, 'pageNo'=> $this->_updatepageNo));

       // $disabledData = $this->_config->request("getProducts", array('recordsOnPage'=> 50, 'pageNo'=> $this->_updatepageNo));

        $this->_products = $disabledData;
        $this->updateProducts();

        echo "Page number: ".$this->_simplepageNo.' and Count '.count($this->_products['records']).'<br>';
        if(count($this->_products['records']) < 50) {
            echo "All the products updated enable/disable.<br>";
            die('End');
        }

        $this->_updatepageNo++;   //Page Number increment.
        $this->updateAllProducts($changedSince); //Run for other pages.
    }

    public function importSimpleProducts() {
        if (empty($this->_simpleProducts) || empty($this->_simpleProducts['records'])) {
            echo 'Empty simple product list.<br>';
            return true;
            //exit();
        }

        foreach ($this->_simpleProducts['records'] as $value) {

            try {
                
                $getProduct = $this->_objectManager->create('Magento\Catalog\Model\Product')->getCollection()
                        ->addAttributeToFilter('name', $value['name'])
                        ->getData();
                
                $nameCount = count($getProduct);

                $sku = $value['code2'];
                if(!$sku) {
                    $sku = $value['code'];
                }
                $qty = $this->getStock($value['productID']); //load stock for this product
                $product = $this->_objectManager->create('\Magento\Catalog\Model\Product');
                if ($product->getIdBySku($sku)) {
                    $_product = $this->_productFactory->create()->load($product->getIdBySku($sku));
                } else {
                    /** @var $installer CategorySetup */
                    $installer = $this->_objectManager->create(CategorySetup::class);
                    $attributeSetId = $installer->getAttributeSetId('catalog_product', 'Default');
                    
                    $_product = $this->_objectManager->create('\Magento\Catalog\Model\Product');
                    $_product->setAttributeSetId($attributeSetId)
                        ->setWebsiteIds(array(1));
                    if($nameCount > 0) {
                        $nameCount++;
                        $nameExist = strtolower($value['name'].'-'.$nameCount);
                        $url = preg_replace('#[^0-9a-z]+#i', '-', $nameExist);
                        $_product->setUrlKey($url);
                    }
                }
                if(array_key_exists("parentProductID", $value) && $value['parentProductID'] > 0) {
                    $_product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
                } else {
                    $_product->setVisibility(Visibility::VISIBILITY_BOTH); 
                }
                
                $this->importImages($value, $_product);
                
                $_product->setSku($sku);
                $_product->setTypeId('simple');
                $_product->setStoreId(0);
                $_product->setPrice($value['price']);
                $_product->setWeight($value['netWeight']);
                $_product->setStatus($value['active']);
                $_product->setName($value['name']);
                $_product->setBrand($this->getBrand($_product, $value['brandID']));
               // $_product->setDescription($value['longdesc']);
               // $_product->setShortDescription($value['description']);

                $_product->setDescription($this->strip_single_tag($value['longdesc'],'em'));
                $_product->setShortDescription($this->strip_single_tag($value['description'],'em'));

                if (!empty($value['variationDescription'])) {
                    $this->setProductVariations($_product, $value);
                }
                if (!empty($value['attributes'])) {
                    $this->setProductAttributes($_product, $value);
                }                 
                //Set category Id based on categoryName
                $this->getCategoryId($_product, $value['categoryName']);


                $_product->save();
                $productId = $_product->getId();
                
                $this->_helper->updateProductStock($sku, $qty, $productId);
                $this->_associated[] = $productId;
                echo $productId. ' saved successfully <br/>';
            } catch (Exception $e) {
                echo $error = "Exception: " . $e->getMessage();
            }
        }
        return true;
    }

    public function importConfigurableProducts() {
        if (empty($this->_associated)) {
            echo 'No Associated product found.<br>';
            return true;
        }

        /** @var $installer CategorySetup */
        $installer = $this->_objectManager->create(CategorySetup::class);
        $attributeSetId = $installer->getAttributeSetId('catalog_product', 'Default');

        /** @var AttributeOptionInterface[] $options */
        $eavConfig = $this->_objectManager->get(\Magento\Eav\Model\Config::class);
        $colorAttribute = $eavConfig->getAttribute('catalog_product', 'color');
        $sizeAttribute = $eavConfig->getAttribute('catalog_product', 'size');
        $eavConfig->clear();
        
        $associatedProductIds = $this->_associated;
        
        $colorAttributeValues = [];
        $sizeAttributeValues = [];
        foreach ($associatedProductIds as $associated) {

            $_product = $this->_objectManager->get('Magento\Catalog\Model\Product')->load($associated);

            if($_product->getColor() != "") {

                $colorAttributeValues[] = [
                    'attribute_id' => $colorAttribute->getId(),
                ];
            }

            if($_product->getSize() != "") {
                $sizeAttributeValues[] = [
                    'attribute_id' => $sizeAttribute->getId(),
                ];
            }
        }

//        $configurableAttributesData = [];
        $attributes = [];
        if(!empty($colorAttributeValues)) {
            $attributes[] = $colorAttribute->getId();
        }
        
        if(!empty($sizeAttributeValues)) {
            $attributes[] = $sizeAttribute->getId();
        }

        $sku = $this->_configurableProducts['code2'];
        if(!$sku) {
            $sku = $this->_configurableProducts['code'];
        }
        
        $getProduct = $this->_objectManager->create('Magento\Catalog\Model\Product')->getCollection()
                ->addAttributeToFilter('name', $this->_configurableProducts['name'])
                ->getData();

        $nameCount = count($getProduct);
        
        /** @var $product Product */
        $product = $this->_objectManager->create('\Magento\Catalog\Model\Product');
        if ($product->getIdBySku($sku)) {
            $product = $this->_productFactory->create()->load($product->getIdBySku($sku));
        } else {
            $product = $this->_objectManager->create('\Magento\Catalog\Model\Product');
            $product->setTypeId('configurable')
                ->setAttributeSetId($attributeSetId)
                ->setWebsiteIds([1])
//                ->setSku($this->_configurableProducts['code2'])
                ->setFeatured(1)
                ->setVisibility(Visibility::VISIBILITY_BOTH);  //Status::STATUS_ENABLED
            if($nameCount > 0) {
                $nameCount++;
                $nameExist = strtolower($this->_configurableProducts['name'].'-'.$nameCount);
                $url = preg_replace('#[^0-9a-z]+#i', '-', $nameExist);
                $product->setUrlKey($url);
            }
        }
      
        $product->getTypeInstance()->setUsedProductAttributeIds($attributes, $product); //attribute ID of attribute 'size_general' in my store
		
        $configurableAttributesData = $product->getTypeInstance()->getConfigurableAttributesAsArray($product);

        $product->setCanSaveConfigurableAttributes(true);
        $product->setConfigurableAttributesData($configurableAttributesData);

        $configurableProductsData = array();
        foreach ($associatedProductIds as $associated) {

            $_product = $this->_objectManager->get('Magento\Catalog\Model\Product')->load($associated);
            if($_product->getColor() != "") {
                $coptionValue = $_product->getColor();

                $configurableProductsData[$associated][0] = array( //[$simple_product_id] = id of a simple product associated with this configurable
                    'label' => 'Color', //attribute label
                    'attribute_id' => $colorAttribute->getId(), //attribute ID of attribute 'size_general' in my store
                    'value_index' => $coptionValue, //value of 'S' index of the attribute 'size_general'
                    'is_percent'    => 0,
                    'position'    => 0,
                    'pricing_value' => $_product->getPrice(),
                );
            }

            if($_product->getSize() != "") {
                $soptionValue = $_product->getSize();

                $configurableProductsData[$associated][1] = array( //[$simple_product_id] = id of a simple product associated with this configurable
                    'label' => 'Size', //attribute label
                    'attribute_id' => $sizeAttribute->getId(), //attribute ID of attribute 'size_general' in my store
                    'value_index' => $soptionValue, //value of 'S' index of the attribute 'size_general'
                    'is_percent'    => 0,
                    'position'    => 1,
                    'pricing_value' => $_product->getPrice(),
                );
            }
        }

        $product->setConfigurableProductsData($configurableProductsData);
        $product->setAffectConfigurableProductAttributes($attributeSetId);
        $this->_objectManager->create('Magento\ConfigurableProduct\Model\Product\Type\Configurable')->setUsedProductAttributeIds($attributes, $product);
        $product->setNewVariationsAttributeSetId($attributeSetId); // Setting Attribute Set Id
        $_children = $product->getTypeInstance()->getUsedProducts($product);
        
        if ($product->getIdBySku($sku)) {

            $table=$this->_resources->getTableName('catalog_product_super_link'); 
            $attributedata = $this->getConnection()->fetchAll('SELECT product_id FROM ' . $table . ' WHERE parent_id = '.$product->getId());
        
            foreach ($attributedata as $child){

                if(($key = array_search($child['product_id'], $associatedProductIds)) !== false) {
                    unset($associatedProductIds[$key]);
                }
            }
            if(!empty($associatedProductIds)) {
                $product->setAssociatedProductIds($associatedProductIds); // Setting Associated Products
                $product->setCanSaveConfigurableAttributes(true);
            }
        } else {
            $product->setAssociatedProductIds($associatedProductIds); // Setting Associated Products
            $product->setCanSaveConfigurableAttributes(true);
        }

        $this->_associated = [];
        try {
            if (!empty($this->_configurableProducts['attributes'])) {
                $this->setProductAttributes($product, $this->_configurableProducts);
            }
            $product->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
            //Set category Id based on categoryName
            $this->getCategoryId($product, $this->_configurableProducts['categoryName']);
            $product->setName($this->_configurableProducts['name'])
                ->setPrice($this->_configurableProducts['price'])
                ->setTypeId('configurable')
                ->setWeight($this->_configurableProducts['netWeight'])
                ->setDescription($this->strip_single_tag($this->_configurableProducts['longdesc'],'em'))
                ->setStoreId(0)
                ->setStatus($this->_configurableProducts['active'])
                ->setSku($sku);
            $product->setShortDescription($this->strip_single_tag($this->_configurableProducts['description'],'em'));
            $product->setBrand($this->getBrand($product, $this->_configurableProducts['brandID']));
            $this->importImages($this->_configurableProducts, $product);
            $product->save();
            
            
        } catch (Exception $e) {
            echo $error = $e->getMessage();
            exit();
        }
    }
    
    public function updateProducts() {

        if (empty($this->_products) || empty($this->_products['records'])) {
            echo 'Empty update product list.';
            exit();
        }

        foreach ($this->_products['records'] as $value) {

            try {
                $sku = $value['code2'];
                if(!$sku) {
                    $sku = $value['code'];
                }
                
                if($value['active'] == 1) {
                    $status = 1;
                } else {
                    $status = 2;
                }
                
                $qty = $this->getStock($value['productID']); //load stock for this product
                $product = $this->_objectManager->create('\Magento\Catalog\Model\Product');
                if ($product->getIdBySku($sku)) {
                    $_product = $this->_productFactory->create()->load($product->getIdBySku($sku));
                    $_product->setStatus($status);
                    $this->importImages($value, $_product);
                    $_product->save();
                }
            } catch (Exception $e) {
                echo $error = "Exception: " . $e->getMessage();
            }
        }
        return true;
    }

    public function getOptionId($_product, $code, $label) {
        $attr = $_product->getResource()->getAttribute($code);
        if ($attr->usesSource()) {
            $option_id = $attr->getSource()->getOptionId($label);
            return $option_id;
        }
        return false;
    }

    public function getOptionText($_product, $code, $id) {
        $attr = $_product->getResource()->getAttribute($code);
        if ($attr->usesSource()) {
            $option_text = $attr->getSource()->getOptionText($id);
            return $option_text;
        }
        return false;
    }

    public function getCategoryId($_product, $title) {
        $collection = $this->_categoryFactory->create()->getCollection()->addAttributeToFilter('name', trim($title))->getFirstItem();
        if ($collection->getData()) {

            $_product->setCategoryIds([$collection->getEntityId()]);
        }
        return $_product;
    }

    public function setProductVariations($_product, $value) {
        foreach ($value['variationDescription'] as $variation) {

            switch ($variation['name']) {

                case 'Size':
                    $_product->setSize($this->getOptionId($_product, 'size', trim($variation['value'])));
                    break;

                case 'Color':
                    $_product->setColor($this->getOptionId($_product, 'color', trim($variation['value'])));
                    break;
            }
        }
        return $_product;
    }

    public function setProductAttributes($_product, $value) {
        foreach ($value['attributes'] as $attribute) {

            $method = strtolower($attribute['attributeName']);
            switch ($attribute['attributeName']) {

                case 'Material':
                    if(!$this->getOptionId($_product, 'material', $attribute['attributeValue'])) {
                        $this->createAttributeOption($attribute['attributeValue'], 'material');
                    }
                    $_product->setMaterial($this->getOptionId($_product, 'material', $attribute['attributeValue']));
                break;

                default:
                    if($attrValue = $this->checkAttribute($attribute['attributeValue'], "$method", $_product)) {
                        $_product->setData("$method", $attrValue);
                    }
                Break;
            }
        }
        return $_product;
    }

    public function checkAttribute($value, $attributeCode, $_product)
    {
        $attributeInfo=$this->_attributeFactory->getCollection()
               ->addFieldToFilter('attribute_code',['eq'=>$attributeCode])
               ->getFirstItem();
        
        if($attributeInfo->getAttributeId()) {

            switch ($attributeInfo->getFrontendInput()) {
                case 'select':
                    return $this->getOptionId($_product, $attributeCode, $value);
                break;

                case 'text':
                    return $value;
                break;
            }
        }
        return false;
    }
    
    public function createAttributeOption($value, $attributeCode)
    {
        $attributeInfo=$this->_attributeFactory->getCollection()
               ->addFieldToFilter('attribute_code',['eq'=>$attributeCode])
               ->getFirstItem();
        
        $option=array();
        $option['attribute_id'] = $attributeInfo->getAttributeId();
        $option['value'][$value][0]=$value;
        
        $storeManager = $this->_objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $storeManager->getStores($withDefault = false);
        
        foreach($storeManager as $store){
            $option['value'][$value][$store] = $value;
        }
        $eavSetup = $this->_eavSetupFactory->create();
        $eavSetup->addAttributeOption($option);
    }

    public function getBrand($_product, $brandId) {

        if(!$this->_brands) {
            $this->_brands = $this->_config->request("getBrands", array());
        }
        
        if(!empty($this->_brands['records'])) {
            foreach($this->_brands['records'] as $brand) {
                
                if($brand['brandID'] == $brandId) {
                    //return $this->checkAttribute($brand['name'], 'brand', $_product); //Check if attribute exists in magento
					
                    return $this->getOptionId($_product, 'brand', $brand['name']);
                    //return $brand['name'];
                }
            }
        }
        return false;
    }

    public function getStock($_productId) {

        $this->getWarehouse(); 

        $stock = $this->_config->request("getProductStock", array('warehouseID' => $this->_warehouseId, 'productID' => $_productId));

        if(!empty($stock['records'])) {
            foreach($stock['records'] as $qty) {
                return $qty['amountInStock'];
            }
        } else {
            return 0;
        }
    }

    public function getWarehouse() {

        if($this->_warehouseId) {
            return $this->_warehouseId;
        }
        $warehouses = $this->_config->request("getWarehouses", array());

        if(!empty($warehouses['records'])) {

            foreach($warehouses['records'] as $warehouse) {

                if($warehouse['code'] == "website") {
                    $this->_warehouseId = $warehouse['warehouseID'];
                    return $this->_warehouseId;
                }
            }
            if(!$this->_warehouseId) {
                die('No warehouse found with this code: head_office');
            }
        }
    }

    public function importImages($data, $_product)
    {
        if(array_key_exists("images", $data) && !empty($data['images'])) {

            foreach($data['images'] as $image) {

                //EXTERNAL IMAGE IMPORT - START                
                if($image['external'] == 0) {
                    if(file_exists($this->_directoryList->getRoot().'/ErplyImport/images/'.$image['name'])) {
                        $image_url = $this->_storeManager->getStore(0)->getBaseUrl().'ErplyImport/images/'.$image['name']; 

// internal image url
                    } else {
                        return true;
                    }
                } else {
                    //EXTERNAL IMAGE IMPORT - START  
                    $image_url  = $image['fullURL']; // external image url
                }
                
                //EXTERNAL IMAGE IMPORT - START
                //$image_url  = $image['fullURL']; // external image url
                $image_type = substr(strrchr($image_url,"."),1); //find the image extension
                $filename   = md5($image_url . $data['code']).'.'.$image_type; //give a new name, you can modify as per your requirement
                $filepath   = $this->_directoryList->getPath('media').'/import/'.$filename; //path for temp storage folder: ./media/import/
				
                $allImageNameArray = array();
                if($_product->getMediaGallery('images')) {
                    foreach($_product->getMediaGallery('images') as $getProductImages) {
                        $getImageName = basename($getProductImages['file']);
                        array_push($allImageNameArray, $getImageName);
                    }
                }
                if(!file_exists($filepath) || (!$_product->getImage() && (!in_array($filename, $allImageNameArray)))) {
                    $curl_handle = curl_init();
                    curl_setopt($curl_handle, CURLOPT_URL, $image_url);
                    curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 120);
                    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($curl_handle, CURLOPT_TIMEOUT, 120);
                    curl_setopt($curl_handle, CURLOPT_USERAGENT, 'Cirkel');
                    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, false);
                    $query = curl_exec($curl_handle);
                    curl_close($curl_handle);                

                    file_put_contents($filepath, $query); //store the image from external url to the temp storage folder

                    //ADD IMAGE TO MEDIA GALLERY
                    $this->addMediaImage($_product, $filepath);
                    //EXTERNAL IMAGE IMPORT - END
                }
            }
        }
        return $_product;
    }

    public function addMediaImage($_product, $filepath)
    {
        $mediaAttribute = array (
            'thumbnail',
            'small_image',
            'image'
        );

        $_product->addImageToMediaGallery($filepath, $mediaAttribute, false, false);
        return $_product;
    }
    
   public function strip_single_tag($str,$tag)
    {
        $str=preg_replace('/<'.$tag.'[^>]*>/i', '', $str);

        $str=preg_replace('/<\/'.$tag.'>/i', '', $str);

        return $str;
    }
    
    protected function getConnection()
    {
        return $this->_resources->getConnection('core_write');
    }
}