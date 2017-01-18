<?php

namespace Erply\Management\Model;

use Magento\Customer\Model\CustomerFactory;
use Erply\Management\Model\Config\Config;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;

class Customer {

    protected $_objectManager;
    protected $_customerFactory;
    protected $_customers;
    protected $_config;
    protected $repository;
    protected $shippingAddress;
    protected $billingAddress;
    protected $_erplyCustomers;
    protected $_storeManager;
    protected $accountManagement;
    protected $_customerRepositoryInterface;
    protected $_pageNo;

    public function __construct(Config $config, CustomerFactory $customerFactory, StoreManagerInterface $storeManager, AccountManagementInterface $accountManagement, CustomerRepositoryInterface $customerRepositoryInterface) {
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_customerFactory = $customerFactory;
        $this->_storeManager = $storeManager;
        $this->accountManagement = $accountManagement;
        $this->_config = $config;
        $this->_customerRepositoryInterface = $customerRepositoryInterface;
        
        $this->_pageNo = 1;
        $this->getAddressType();
    }

    public function getCustomerData() {

        if (isset($this->_customers)) {
            return $this->_customers;
        }

        $this->getErplyCustomers();

        $this->_customers = $this->_customerFactory->create()->getCollection();
        //echo "<pre>"; print_r($this->_customers->getData()); die('a');
        return $this->syncCustomers($this->_customers->getData());
    }

    public function syncCustomers() {
        
        if (!count($this->_customers)) {
            echo 'No customer exists sync customer.<br>';
        }

        $this->repository = $this->_objectManager->create('Magento\Customer\Api\AddressRepositoryInterface');
        $records = $this->_customers;
        foreach ($records as $record) {

            $address = $this->_objectManager->create('Magento\Customer\Model\Address')->load($record->getDefaultBilling());
            $value = $record->getData();
            $gender = "";
            if($value['gender'] == 1) {
                $gender = 'male';
            } else if($value['gender'] == 2) {
                $gender = 'female';
            }
            $customerId = $this->getCustomer($value['email']);
            if ($customerId) {
                $data = array('customerID' => $customerId, 'username'=> $value['email'], 'password'=>'', 'firstName' => $value['firstname'], 'lastName' => $value['lastname'], 'email' => $value['email'], 'mobile' => $address->getTelephone() > 0 ? $address->getTelephone() : "", 'phone' => $address->getTelephone() > 0 ? $address->getTelephone() : "", 'companyName' => $address->getCompany(), 'gender'=> $gender);
            } else {
                $data = array('username'=> $value['email'], 'password'=>'', 'firstName' => $value['firstname'], 'lastName' => $value['lastname'], 'email' => $value['email'], 'mobile' => $address->getTelephone() > 0 ? $address->getTelephone() : "", 'phone' => $address->getTelephone() > 0 ? $address->getTelephone() : "", 'companyName' => $address->getCompany(), 'gender'=> $gender);
            }

            $response = $this->_config->request("saveCustomer", $data);
            $this->syncAddress($response['records'][0]['clientID'], $record);
        }

        echo "Customers synced successfully from Magento to Erply";
        exit;
    }

    public function getErplyCustomers() {
        
        $changedSince = "";
        if($this->lastSync() > 2) {
            $changedSince = strtotime('-1 day');
            $this->_erplyCustomers = $this->_config->request("getCustomers", array('changedSince' => $changedSince, 'pageNo'=> $this->_pageNo));
        } else {
            $this->_erplyCustomers = $this->_config->request("getCustomers", array('pageNo'=> $this->_pageNo));
        }

        echo "Page number: ".$this->_pageNo.' and Count '.count($this->_erplyCustomers['records']).'<br>';
        $this->importCustomer();
        
        if(count($this->_erplyCustomers['records']) < 20) {
            echo "Customers synced successfully from Erply to Magento"; //All Customers are imported.
            return true;
        }
        $this->_pageNo++;   //Page Number increment.
        $this->getErplyCustomers(); //Run for other pages.

//        echo "<pre>";
//        print_r($this->_erplyCustomers);
//        die('here');
        //echo "Customers synced successfully from Erply to Magento"; //die('here');;
    }
    
    public function importCustomer() {

        if (empty($this->_erplyCustomers) && empty($this->_erplyCustomers['records'])) {
            echo 'No Customer found import customer. <br>';
        }

        // Get Website ID
        $websiteId = $this->_storeManager->getWebsite()->getWebsiteId();
        foreach ($this->_erplyCustomers['records'] as $record) {

//            echo filter_var($record['email'], FILTER_VALIDATE_EMAIL).'---';
            if($record['email'] != '' && !filter_var($record['email'], FILTER_VALIDATE_EMAIL) === false) {
            
                $customer = $this->_customerFactory->create()->getCollection()->addFieldToFilter('email', $record['email'])->getFirstItem();

                if (empty($customer->getData())) {
                    // Instantiate object (this is the most important part)
                    $customer = $this->_customerFactory->create();
                }

                $name = $record['fullName'];
                $fname = $record['firstName'];
                $lname = $record['lastName'];
                if($name != "") {
                    if($record['firstName'] == "") {
                        $fname = $name;
                    }
                    if($record['lastName'] == "") {
                        $lname = $name;
                    }
                }

                $customer->setWebsiteId(1);
                $customer->setEmail($record['email']);
                $customer->setFirstname($fname);
                $customer->setLastname($lname);
                //$customer->setPassword("password");
                //echo $record['email'].'<br>';
                try {
                    $customer->save();
                    #$customer->sendNewAccountEmail();
                    $this->syncAddressMagento($customer, $record);
                    echo $customer->getEmail().' Saved Successfully.<br>';
                } catch (Exception $e) {
                    echo $error = "Exception: " . $e->getMessage();
                }
            } else {
                echo $record['email'].' NOT SAVED. <br>';
            }
        }
    }

    public function syncAddress($customerId, $record) {

        /* Shipping Address */
        $addedAddresses = $this->_objectManager->create('Magento\Customer\Model\Address')->getCollection()->addFieldToFilter('parent_id',['eq'=> $record->getId()]);

        if(!empty($addedAddresses->getData())) {

            foreach($addedAddresses as $addedAddress) {

                $street = '';
                if (count($addedAddress->getStreet()) > 0) {
                    $street = $addedAddress->getStreet()[0];
                }
                $address2 = '';
                if (count($addedAddress->getStreet()) > 1) {
                    $address2 = $addedAddress->getStreet()[1];
                }

                $shippingData = array('ownerID' => $customerId, 'street' => $street,
                        'address2' => $address2, 'city' => $addedAddress->getCity(), 'postalCode' => $addedAddress->getPostcode(),
                        'state' => $addedAddress->getRegion(), 'country' => $addedAddress->getCountry(), 'typeID' => $this->shippingAddress);
                if($record->getDefaultBilling() == $addedAddress['entity_id']) {
                    $shippingData['typeID'] = $this->billingAddress;
                }

                $addressId = $this->getAddresses($customerId, $shippingData);
                if ($addressId) {
                    $shippingData['addressID'] = $addressId;
                }

                $this->_config->request("saveAddress", $shippingData);
            }
        } else {
            echo "No Address found for ".$record['email'].'<br>';
        }
    }

    public function syncAddressMagento($customer, $record) {

        $erplyAddress = $this->_config->request("getAddresses", array('ownerID' => $record['id'], 'recordsOnPage' => 100));

        if (!empty($erplyAddress) && !empty($erplyAddress['records'])) {
            
            //echo "<pre>"; print_r($erplyAddress['records']);            
            foreach ($erplyAddress['records'] as $eAddress) {

                if ($eAddress['street'] || $eAddress['city'] || $eAddress['state'] || $eAddress['country'] || $eAddress['postcode']) {

                    $addedAddresses = $this->_objectManager->create('Magento\Customer\Model\Address')->getCollection()->addFieldToFilter('parent_id',['eq'=> $customer->getId()]);
            
                    $addressId = '';
                    if(!empty($addedAddresses->getData())) {

                        foreach($addedAddresses as $addedAddress) {

                            $strt = $addedAddress->getStreet();
                            $addedAddress = $addedAddress->getData();
                            $stret = $eAddress['street'].$eAddress['address2'];
                            if($stret == implode("", $strt) && $eAddress['city'] == $addedAddress['city'] && $eAddress['state'] == $addedAddress["region"] && $eAddress['country'] == $addedAddress['country_id'] && $eAddress['postcode'] == $addedAddress['postcode']) {
    
                                $addressId = $addedAddress['entity_id'];
                                break;
                            }
                        }
                    }
                    
                    /*if($record['mobile'] == "") {
                        $record['mobile'] = 0;
                    }*/

                    if($addressId == "") {

                        $addresss = $this->_objectManager->get('\Magento\Customer\Model\AddressFactory');
                        $address = $addresss->create();
                        $address->setCustomerId($customer->getId())
                            ->setFirstname($customer->getFirstname())
                            ->setLastname($customer->getLastname())
                            ->setCountryId(($eAddress['country'] != "") ? $eAddress['country'] : 'Not Provided')
                            //->setRegionId('1') //state/province, only needed if the country is USA
                            ->setPostcode(($eAddress['postcode'] != "") ? $eAddress['postcode'] : '0')
                            ->setCity(($eAddress['city'] != "") ? $eAddress['city'] : 'Not Provided')
                            ->setTelephone(($record['mobile'] != "") ? $record['mobile'] : '0')
                            ->setRegion(($eAddress['state'] != "") ? $eAddress['state'] : 'Not Provided')
                            //->setFax($eAddress['']')
                            ->setCompany($record['companyName'])
                            ->setIsDefaultBilling($eAddress['typeActivelyUsed'])
                            ->setIsDefaultShipping($eAddress['typeActivelyUsed'])
                            ->setSaveInAddressBook(1);

                        if($eAddress['address2'] != '') {
                            $street = [];
                            $street[] = ($eAddress['street'] != "") ? $eAddress['street'] : 'Not Provided';
                            $street[] = ($eAddress['address2'] != "") ? $eAddress['address2'] : 'Not Provided';
                            $address->setStreet($street);
                        } else {
                            $address->setStreet(($eAddress['street'] != "") ? $eAddress['street'] : 'Not Provided');
                        }
                        try {
                            $address->save();
                        } catch (Exception $e) {
                            echo $error = "Address Exception: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }

    public function getAddressType($lang = 'eng') {

        if ($this->shippingAddress && $this->billingAddress) {
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

    public function getAddresses($ownerId, $data) {

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

    public function getCustomer($email) {

        $response = $this->_config->request("getCustomers", array('searchName'=> $email));

        //echo "<pre>"; print_r($response); die('here');        
        if (!empty($response['records'])) {
            foreach ($response['records'] as $record) {
                if ($record['email'] == $email) {
                    return $record['id'];
                    break;
                }
            }
        }
        return false;
    }
    
    public function lastSync() {

        $data = $this->_config->getLastSync('cron_erply_customer');
        
        if($data) {
            return $data;
        }
        
        return false;
    }

}
