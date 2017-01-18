<?php
namespace Erply\Management\Helper;
 
 
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $_product;
 
    /**
     * @var Magento\CatalogInventory\Api\StockStateInterface 
     */
    protected $_stockStateInterface;
 
    /**
     * @var Magento\CatalogInventory\Api\StockRegistryInterface 
     */
    protected $_stockRegistry;
 
    /**
    * @param Magento\Framework\App\Helper\Context $context
    * @param Magento\Catalog\Model\Product $product
    * @param Magento\CatalogInventory\Api\StockStateInterface $stockStateInterface,
    * @param Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry
    */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Catalog\Model\Product $product,
        \Magento\CatalogInventory\Api\StockStateInterface $stockStateInterface,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry
    ) {
        $this->_product = $product;
        $this->_stockStateInterface = $stockStateInterface;
        $this->_stockRegistry = $stockRegistry;
        parent::__construct($context);
    }
 
    /**
     * For Update stock of product
     * @param int $productId which stock you want to update
     * @param array $stockData your updated data
     * @return void 
    */
    public function updateProductStock($sku, $qty, $productId) {

        //Need to load stock item
        $stockItem = $this->_stockRegistry->getStockItem($productId);
        $stockItem->setData('qty', $qty); //set updated quantity
        $stockItem->setData('is_in_stock', $qty > 0);
        //$stockItem->setData('manage_stock',$stockData['manage_stock']);
        //$stockItem->setData('use_config_notify_stock_qty',1);

        $this->_stockRegistry->updateStockItemBySku($sku, $stockItem);
    }
}