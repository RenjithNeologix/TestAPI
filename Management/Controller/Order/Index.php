<?php
 
namespace Erply\Management\Controller\Order;
 
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Erply\Management\Model\Customer;
use Erply\Management\Model\Order;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $_resultPageFactory;
    protected $_scopeConfig;
    protected $_modelOrder;
    protected $_modelCustomer;
    public $messageManager;

    public function __construct(Context $context, ScopeConfigInterface $scopeConfig, Customer $customer, \Psr\Log\LoggerInterface $logger, Order $order)
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_modelOrder = $order;
        $this->_modelCustomer = $customer;
        $this->_logger = $logger;
        $this->messageManager = $context->getMessageManager();

        parent::__construct($context);
    }
 
    public function execute()
    {
        $this->_logger->info(__METHOD__);
        if(!$this->_scopeConfig->getValue('management/mainpage/option', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)){
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Please activate Erply Magento extension from admin panel.', 2004)
            );
        }
        $this->_modelOrder->getOrders();
    }
}