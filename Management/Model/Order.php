<?php

namespace Erply\Management\Model;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Erply\Management\Model\Config\Config;
use Erply\Management\Model\Product;
use Erply\Management\Model\Customer;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Order {

    protected $_objectManager;
    protected $_orderCollectionFactory;
    protected $orders;
    protected $_warehouse;
    protected $_customer;
    protected $scope;
    protected $shippingAddress;
    protected $billingAddress;
    protected $_shippingConfig;
    protected $_scopeConfig;

    public function __construct(CollectionFactory $orderCollectionFactory, Product $product, Customer $customer, Config $config, ScopeConfigInterface $scope,
            \Magento\Shipping\Model\Config $shippingConfig, \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig)
    {
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_warehouse = $product->getWarehouse();
        $this->_customer = $customer;
        $this->_config = $config;
        $this->scope = $scope;
        $this->_shippingConfig = $shippingConfig;
        $this->_scopeConfig = $scopeConfig;
        $this->getAddressType();
    }

    public function getOrders() {

        if (!$this->orders) {
            $this->orders = $this->_orderCollectionFactory->create()
                ->addFieldToSelect('*')
                ->addFieldToFilter('status', 'pending');
        }
        $this->syncOrders();
    }
    
    public function syncOrders() {

        if (!$this->_warehouse) {
            die("No warehouse found");
        }

        if (!$this->orders->getData()) {
            echo "No Order found <br><br>";
        }
        $methods = $this->getAllMethods($isActiveOnlyFlag = true);
        //TYPE: INVWAYBILL, CASHINVOICE, WAYBILL, PREPAYMENT, OFFER, EXPORTINVOICE, RESERVATION, ORDER, INVOICE, CREDITINVOICE.
        foreach($this->orders as $order) {

            $shipping = $order->getShippingAddress();   //Get Shipping Address

            //echo "<pre>"; print_r($shipping->getData()); 
            
            $orderData = $order->getData();     //Get Order Data
            
            $date = $time = "";
            if($orderData['created_at']) {
                $dateTime = explode(' ', $orderData['created_at']);
                $date = $dateTime[0];
                $time = $dateTime[1];
            }
            $note = "Transaction done using ".$order->getOrderCurrencyCode().'.';
            if($order->getOrderCurrencyCode() != $order->getBaseCurrencyCode()) {
                
                if($order->getStoreCurrencyCode() == $order->getOrderCurrencyCode()) {
                    $note = "Transaction done using ".$order->getOrderCurrencyCode().'. '.$order->getBaseCurrencyCode().' equivalent is '. number_format($order->getGrandTotal(), 2, '.', '').".";
                } else {
                    $note = "Transaction done using ".$order->getOrderCurrencyCode().'. '.$order->getBaseCurrencyCode().' equivalent is '. number_format($order->getBaseGrandTotal(), 2, '.', '').".";
                }
            }

            $method = "";
            if(array_key_exists($order->getShippingMethod(), $methods)){
                $method = " Shipping method is ".$methods[$order->getShippingMethod()];
            } else {
                $method = " Shipping method is ".$order->getShippingMethod();
            }
            
            $storeName = ".";
            if($order->getShippingMethod() == "storepickup_storepickup") {
                $storeName = '. Store name is '.$shipping->getFirstName().' '.$shipping->getLastName().'.';
            }
            
            $note .= $method.". Shipping amount is ".$order->getShippingAmount().' and payment method is '.$order->getPayment()->getMethodInstance()->getTitle().$storeName;
            
            $orderItems = $order->getAllItems();
            $street = '';
            if (count($shipping->getStreet()) > 0) {
                $street = $shipping->getStreet()[0];
            }
            $address2 = '';
            if (count($shipping->getStreet()) > 1) {
                $address2 = $shipping->getStreet()[1];
            }

            if($order->getCustomerId()){
                $email = $order->getCustomerEmail(); //logged in customer
            }
            else{
                $email = $order->getBillingAddress()->getEmail(); //not logged in customer
            }
            
            $customerID = $this->_customer->getCustomer($order->getCustomerEmail());

            $shippingData = array('ownerID' => $customerID, 'street' => $street,
                    'address2' => $address2, 'city' => $shipping->getCity(), 'postalCode' => $shipping->getPostcode(),
                    'state' => $shipping->getRegion(), 'country' => $shipping->getCountryId(), 'typeID' => $this->shippingAddress);
            
            $data = array(
                'type'=> 'ORDER',
                'currencyCode'=> $order->getOrderCurrencyCode(),
                'currencyRate'=> 1.0,
                'warehouseID'=> $this->_warehouse,
                'date'=> $date,
                'time'=> $time,
                'customerID'=> $customerID,
                'addressID'=> $this->getCustomerAddressId($customerID, $shippingData),
                'confirmInvoice' => 1,
                'shippingDate'=> $date,
                'shipToID' => $this->getCustomerAddressId($customerID, $shippingData),
                'shipToAddressID' => $this->getCustomerAddressId($customerID, $shippingData),
                'notes'=> $note,
                'invoiceState'=> '',
                'paymentType'=> $this->getPaymentType($order->getPayment()->getMethodInstance()->getCode()),
                //'paymentTypeID',
                'sendByEmail'=> 1,
                'paymentStatus'=> $this->getOrderPaymentStatus($order->getState()),
                'transactionTypeID'=> '',
                'transportTypeID'=> '',
                'purchaseOrderDone'=> ($order->getState() == 'complete') ? 1 : 0,
                'vat'=> $order->getShippingInclTax(),
                'customNumber' => $order->getEntityId()
            );
            if($id = $this->getOrderId($order->getEntityId(), $customerID)) {
                $data['id'] = $id;
            }
            
            $i = 0;
            $price = 0;
            $orgPrice = 0;
            foreach($orderItems as $item) {
                
                
//                $product = $item->getData();
                if($item->getProductType() == 'configurable') {
                    $qty = (int)$item->getQtyOrdered();
                    $price = $item->getPriceInclTax();
                    $orgPrice = $item->getOriginalPrice();
                } else {

                    if((int)$item->getQtyOrdered() > 0) {
                        $qty = (int)$item->getQtyOrdered();
                    }
                    if($item->getPriceInclTax() > 0) {
                        $price = $item->getPriceInclTax();
                    }
                    if($item->getOriginalPrice() > 0) {
                        $orgPrice = $item->getOriginalPrice();
                    }
                    
                    $i++;
                    $sku = $item->getSku();
                    $data["productID$i"]= $this->getErplyProductId($sku);
                    $data["itemName$i"]= $item->getName();
                    $data["amount$i"]= $qty;
                    $data["TotalSalesTax$i"] = $price;
                    $data["price$i"]= $orgPrice;
                    $data["ZIPCode$i"]= $shipping->getPostcode();
                    $data["State$i"]= $shipping->getRegion();
                    $data["County$i"]= $shipping->getCountryId();
                    $data["City$i"]= $shipping->getCity();
                    $data["Category$i"]= '';
                }
//                echo "<pre> Item : "; print_r($item->getData()); 
            }
            $response = $this->_config->request("saveSalesDocument", $data);
            
            
            echo "<pre>Request : "; print_r($response); 
        }
        die('Orders imported');
    }
    
    public function getPaymentType($method) {

        /*$methodList = $this->scope->getValue('payment');
        foreach( $methodList as $code => $_method ) {
            echo $code.'<bR>';//.'--'.$_method;
            echo "<pre>"; print_r($_method);
        }*/

        $paymentTypes = array(
            'free'=> 'CASH', 
            'cashondelivery'=> 'CASH',
            'payflowpro_cc_vault'=> 'CARD', 
            'braintree_cc_vault'=> 'CARD', 
            'substitution'=>  'TRANSFER', 
            'banktransfer'=> 'TRANSFER', 
            'paypal_express'=> 'TRANSFER', 
            'paypal_express_bml'=> 'CARD', 
            'payflow_express_bml'=> 'TRANSFER', 
            'payflowpro'=> 'TRANSFER', 
            'paypal_billing_agreement'=> 'TRANSFER', 
            'payflow_link'=> 'TRANSFER', 
            'payflow_advanced'=> 'TRANSFER',
            'hosted_pro'=> 'TRANSFER', 
            'authorizenet_directpost'=> 'TRANSFER',
            'braintree'=> 'TRANSFER', 
            'braintree_paypal'=> 'TRANSFER',
            'checkmo'=> 'CHECK'); 
            //''=> 'GIFTCARD');

        if(array_key_exists($method, $paymentTypes)){
            return $paymentTypes[$method];
        } 
        return 'TRANSFER';//$paymentTypes["$method"];
    }

    public function getOrderPaymentStatus($state) {

        if($state == "pending_payment" || $state == "closed" || $state == "canceled" || $state == "holded" || $state == "new") {
            return "UNPAID";
        }
        
        return "PAID"; //For processing, complete, payment_review
    }

    public function getErplyProductId($sku) {

        $data = array('code2'=> $sku);
        $response = $this->_config->request("getProducts", $data);

        //Check if product has code2
        if (!empty($response) && !empty($response['records'])) {
            return $response['records']['0']['productID'];
        }

        //Check if product has no code2
        $simpleData = array('code'=> $sku);
        $simpleResponse = $this->_config->request("getProducts", $simpleData);

        if (!empty($simpleResponse) && !empty($simpleResponse['records'])) {
            return $simpleResponse['records']['0']['productID'];
        }

        echo "Product not found on Erply.";
        return false;
    }

    public function getCustomerAddressId($ownerId, $data) {

        $addressData = array('ownerID' => $ownerId);
        $response = $this->_config->request("getAddresses", $addressData);

        if (!empty($response['records'])) {

            foreach (($response['records']) as $record) {

                if ($record['country'] == $data['country'] && $record['state'] == $data['state'] && $record['city'] == $data['city'] && $record['postcode'] == $data['postalCode'] && $record['street'] == $data['street'] && $record['address2'] == $data['address2']) {
                    return $record['addressID'];
                }
            }
        }

        return false;
    }

    public function getAddressType($lang = 'eng') {

        if ($this->shippingAddress) { // && $this->billingAddress) {
            return true;
        }

        $AddressTypes = $this->_config->request("getAddressTypes", array('lang' => $lang));
        $records = $AddressTypes['records'];
        foreach ($records as $record) {

            switch ($record['name']) {
                case 'mailing address':
                    $this->billingAddress = $record['id'];
                    break;

                case 'registered address':
                    $this->shippingAddress = $record['id'];
                    break;
            }
        }
    }
    
    public function getAllMethods($isActiveOnlyFlag = true) {

        $methods = [];
        $carriers = $this->_shippingConfig->getAllCarriers();
        foreach ($carriers as $carrierCode => $carrierModel) {
            if (!$carrierModel->isActive() && (bool)$isActiveOnlyFlag === true) {
                continue;
            }
            $carrierMethods = $carrierModel->getAllowedMethods();
            if (!$carrierMethods) {
                continue;
            }
            $carrierTitle = $this->_scopeConfig->getValue(
                'carriers/' . $carrierCode . '/title',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            $methods[$carrierCode] = ['label' => $carrierTitle, 'value' => []];
            foreach ($carrierMethods as $methodCode => $methodTitle) {
                $methods[$carrierCode . '_' . $methodCode] = '[' . $carrierCode . '] ' . $methodTitle;
            }
        }

        return $methods;
    }

    public function getOrderId($customNumber, $customerId) {

        $response = $this->_config->request("getSalesDocuments", array('type' => 'ORDER', 'number' => $customNumber));

        //echo "<pre>"; print_r($response); die('here');        
        if (!empty($response['records'])) {
            //foreach ($response['records'] as $record) {
                //if ($record['number'] == $customNumber) {
                    return $response['records'][0]['id'];
                    //break;
                //}
            //}
        }
        return false;
    }
}