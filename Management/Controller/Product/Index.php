<?php
 
namespace Erply\Management\Controller\Product;
 
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Erply\Management\Model\Product;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $_resultPageFactory;
    protected $_scopeConfig;
    protected $_modelProduct;
    public $messageManager;

    public function __construct(Context $context, ScopeConfigInterface $scopeConfig, Product $product)
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_modelProduct = $product;
        $this->messageManager = $context->getMessageManager();

        parent::__construct($context);
    }
 
    public function execute()
    {
        if(!$this->_scopeConfig->getValue('management/mainpage/option', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)){
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Please activate Erply Magento extension from admin panel.', 2004)
            );
        }
        $this->_modelProduct->create();
    }
}