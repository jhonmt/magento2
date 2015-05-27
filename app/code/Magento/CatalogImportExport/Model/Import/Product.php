<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Magento\CatalogImportExport\Model\Import;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\CatalogImportExport\Model\Import\Product\RowValidatorInterface as ValidatorInterface;
use Magento\Framework\Model\Resource\Db\TransactionManagerInterface;
use Magento\Framework\Model\Resource\Db\ObjectRelationProcessor;
use Magento\Framework\Stdlib\DateTime;


/**
 * Import entity product model
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Product extends \Magento\ImportExport\Model\Import\Entity\AbstractEntity
{
    const CONFIG_KEY_PRODUCT_TYPES = 'global/importexport/import_product_types';

    /**
     * Size of bunch - part of products to save in one step.
     */
    const BUNCH_SIZE = 20;

    /**
     * Size of bunch to delete attributes of products in one step.
     */
    const ATTRIBUTE_DELETE_BUNCH = 1000;

    /**
     * default delimiter for several values in one cell
     */
    const DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR = ',';

    /**
     * Pseudo multi line separator in one cell.
     *
     * Can be used as custom option value delimiter or in configurable fields cells.
     */
    const PSEUDO_MULTI_LINE_SEPARATOR = '|';

    /**
     * Symbol between Name and Value between Pairs.
     */
    const PAIR_NAME_VALUE_SEPARATOR = '=';

    /**
     * Value that means all entities (e.g. websites, groups etc.)
     */
    const VALUE_ALL = 'all';

    /**
     * Data row scopes.
     */
    const SCOPE_DEFAULT = 1;

    const SCOPE_WEBSITE = 2;

    const SCOPE_STORE = 0;

    const SCOPE_NULL = -1;

    /**
     * Permanent column names.
     *
     * Names that begins with underscore is not an attribute. This name convention is for
     * to avoid interference with same attribute name.
     */

    /**
     * Column product store.
     */
    const COL_STORE = '_store';

    /**
     * Column website.
     */
    const COL_WEBSITE = 'website_code';

    /**
     * Column product attribute set.
     */
    const COL_ATTR_SET = '_attribute_set';

    /**
     * Column product type.
     */
    const COL_TYPE = 'product_type';

    /**
     * Column product category.
     */
    const COL_CATEGORY = 'categories';

    /**
     * Column product sku.
     */
    const COL_SKU = 'sku';

    /**
     * Column product name.
     */
    const COL_NAME = 'name';

    /**
     * Column product website.
     */
    const COL_PRODUCT_WEBSITES = '_product_websites';

    /**
     * Media gallery attribute code.
     */
    const MEDIA_GALLERY_ATTRIBUTE_CODE = 'media_gallery';

    /**
     * Column media image.
     */
    const COL_MEDIA_IMAGE = '_media_image';

    /**
     * Inventory use config.
     */
    const INVENTORY_USE_CONFIG = 'Use Config';

    /**
     * Inventory use config prefix.
     */
    const INVENTORY_USE_CONFIG_PREFIX = 'use_config_';

    /**
     * Attribute cache
     *
     * @var array
     */
    protected $_attributeCache = [];

    /**
     * Pairs of attribute set ID-to-name.
     *
     * @var array
     */
    protected $_attrSetIdToName = [];

    /**
     * Pairs of attribute set name-to-ID.
     *
     * @var array
     */
    protected $_attrSetNameToId = [];

    /**
     * Attributes with index (not label) value.
     *
     * @var string[]
     */
    protected $_indexValueAttributes = [
        'status',
        'tax_class_id',
        'gift_message_available',
    ];

    /**
     * Links attribute name-to-link type ID.
     *
     * @var array
     */
    protected $_linkNameToId = [
        '_related_' => \Magento\Catalog\Model\Product\Link::LINK_TYPE_RELATED,
        '_crosssell_' => \Magento\Catalog\Model\Product\Link::LINK_TYPE_CROSSSELL,
        '_upsell_' => \Magento\Catalog\Model\Product\Link::LINK_TYPE_UPSELL,
    ];

    /**
     * Attribute id for product images storage.
     *
     * @var array
     */
    protected $_media_gallery_attribute_id = null;

    /**
     * Validation failure message template definitions
     *
     * @var array
     */
    protected $_messageTemplates = [
        ValidatorInterface::ERROR_INVALID_SCOPE => 'Invalid value in Scope column',
        ValidatorInterface::ERROR_INVALID_WEBSITE => 'Invalid value in Website column (website does not exists?)',
        ValidatorInterface::ERROR_INVALID_STORE => 'Invalid value in Store column (store does not exists?)',
        ValidatorInterface::ERROR_INVALID_ATTR_SET => 'Invalid value for Attribute Set column (set does not exists?)',
        ValidatorInterface::ERROR_INVALID_TYPE => 'Product Type is invalid or not supported',
        ValidatorInterface::ERROR_INVALID_CATEGORY => 'Category does not exists',
        ValidatorInterface::ERROR_VALUE_IS_REQUIRED => "Required attribute '%s' has an empty value",
        ValidatorInterface::ERROR_TYPE_CHANGED => 'Trying to change type of existing products',
        ValidatorInterface::ERROR_SKU_IS_EMPTY => 'SKU is empty',
        ValidatorInterface::ERROR_NO_DEFAULT_ROW => 'Default values row does not exists',
        ValidatorInterface::ERROR_CHANGE_TYPE => 'Product type change is not allowed',
        ValidatorInterface::ERROR_DUPLICATE_SCOPE => 'Duplicate scope',
        ValidatorInterface::ERROR_DUPLICATE_SKU => 'Duplicate SKU',
        ValidatorInterface::ERROR_CHANGE_ATTR_SET => 'Product attribute set change is not allowed',
        ValidatorInterface::ERROR_TYPE_UNSUPPORTED => 'Product type is not supported',
        ValidatorInterface::ERROR_ROW_IS_ORPHAN => 'Orphan rows that will be skipped due default row errors',
        ValidatorInterface::ERROR_INVALID_TIER_PRICE_QTY => 'Tier Price data price or quantity value is invalid',
        ValidatorInterface::ERROR_INVALID_TIER_PRICE_SITE => 'Tier Price data website is invalid',
        ValidatorInterface::ERROR_INVALID_TIER_PRICE_GROUP => 'Tier Price customer group ID is invalid',
        ValidatorInterface::ERROR_TIER_DATA_INCOMPLETE => 'Tier Price data is incomplete',
        ValidatorInterface::ERROR_SKU_NOT_FOUND_FOR_DELETE => 'Product with specified SKU not found',
        ValidatorInterface::ERROR_SUPER_PRODUCTS_SKU_NOT_FOUND => 'Product with specified super products SKU not found',
        ValidatorInterface::ERROR_MEDIA_DATA_INCOMPLETE => 'Media data is incomplete',
        ValidatorInterface::ERROR_INVALID_WEIGHT => 'Product weight is invalid',
    ];

    /**
     * Map between import file fields and system fields/attributes.
     *
     * @var array
     */
    protected $_fields_map = [
        'image' => 'base_image',
        'image_label' => "base_image_label",
        'image' => 'base_image',
        'image_label' => 'base_image_label',
        'thumbnail' => 'thumbnail_image',
        'thumbnail_label' => 'thumbnail_image_label',
        self::COL_MEDIA_IMAGE => 'additional_images',
        '_media_image_label' => 'additional_image_labels',
        Product::COL_STORE => 'store_view_code',
        Product::COL_ATTR_SET => 'attribute_set_code',
        Product::COL_TYPE => 'product_type',
        Product::COL_PRODUCT_WEBSITES => 'product_websites',
        'status' => 'product_online',
        'news_from_date' => 'new_from_date',
        'news_to_date' => 'new_to_date',
        'options_container' => 'display_product_options_in',
        'minimal_price' => 'map_price',
        'msrp' => 'msrp_price',
        'msrp_enabled' => 'map_enabled',
        'special_from_date' => 'special_price_from_date',
        'special_to_date' => 'special_price_to_date',
        'min_qty' => 'out_of_stock_qty',
        'backorders' => 'allow_backorders',
        'min_sale_qty' => 'min_cart_qty',
        'max_sale_qty' => 'max_cart_qty',
        'notify_stock_qty' => 'notify_on_stock_below',
        '_related_sku' => 'related_skus',
        '_crosssell_sku' => 'crosssell_skus',
        '_upsell_sku' => 'upsell_skus',
        'meta_keyword' => 'meta_keywords',
    ];

    /**
     * Existing products SKU-related information in form of array:
     *
     * [SKU] => array(
     *     'type_id'        => (string) product type
     *     'attr_set_id'    => (int) product attribute set ID
     *     'entity_id'      => (int) product ID
     *     'supported_type' => (boolean) is product type supported by current version of import module
     * )
     *
     * @var array
     */
    protected $_oldSku = [];

    /**
     * Column names that holds values with particular meaning.
     *
     * @var string[]
     */
    protected $_specialAttributes = [
        self::COL_STORE,
        self::COL_ATTR_SET,
        self::COL_TYPE,
        self::COL_CATEGORY,
        '_product_websites',
        self::COL_PRODUCT_WEBSITES,
        '_tier_price_website',
        '_tier_price_customer_group',
        '_tier_price_qty',
        '_tier_price_price',
        '_related_sku',
        '_group_price_website',
        '_group_price_customer_group',
        '_group_price_price',
        '_related_position',
        '_crosssell_sku',
        '_crosssell_position',
        '_upsell_sku',
        '_upsell_position',
        '_custom_option_store',
        '_custom_option_type',
        '_custom_option_title',
        '_custom_option_is_required',
        '_custom_option_price',
        '_custom_option_sku',
        '_custom_option_max_characters',
        '_custom_option_sort_order',
        '_custom_option_file_extension',
        '_custom_option_image_size_x',
        '_custom_option_image_size_y',
        '_custom_option_row_title',
        '_custom_option_row_price',
        '_custom_option_row_sku',
        '_custom_option_row_sort',
        '_media_attribute_id',
        self::COL_MEDIA_IMAGE,
        '_media_label',
        '_media_position',
        '_media_is_disabled',
    ];

    /**
     * @var array
     */
    protected $defaultStockData = [
        'manage_stock' => 1,
        'use_config_manage_stock' => 1,
        'qty' => 0,
        'min_qty' => 0,
        'use_config_min_qty' => 1,
        'min_sale_qty' => 1,
        'use_config_min_sale_qty' => 1,
        'max_sale_qty' => 10000,
        'use_config_max_sale_qty' => 1,
        'is_qty_decimal' => 0,
        'backorders' => 0,
        'use_config_backorders' => 1,
        'notify_stock_qty' => 1,
        'use_config_notify_stock_qty' => 1,
        'enable_qty_increments' => 0,
        'use_config_enable_qty_inc' => 1,
        'qty_increments' => 0,
        'use_config_qty_increments' => 1,
        'is_in_stock' => 1,
        'low_stock_date' => null,
        'stock_status_changed_auto' => 0,
        'is_decimal_divided' => 0,
    ];

    /**
     * Column names that holds images files names
     *
     * @var string[]
     */
    protected $_imagesArrayKeys = ['_media_image', 'image', 'small_image', 'thumbnail'];

    /**
     * Permanent entity columns.
     *
     * @var string[]
     */
    protected $_permanentAttributes = [self::COL_SKU];

    /**
     * Array of supported product types as keys with appropriate model object as value.
     *
     * @var array
     */
    protected $_productTypeModels = [];

    /**
     * Media files uploader
     *
     * @var \Magento\CatalogImportExport\Model\Import\Uploader
     */
    protected $_fileUploader;

    /**
     * Import entity which provide import of product custom options
     *
     * @var \Magento\CatalogImportExport\Model\Import\Product\Option
     */
    protected $_optionEntity;

    /**
     * Catalog data
     *
     * @var \Magento\Catalog\Helper\Data
     */
    protected $_catalogData = null;

    /**
     * @var \Magento\CatalogInventory\Api\StockRegistryInterface
     */
    protected $stockRegistry;

    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface
     */
    protected $stockConfiguration;

    /**
     * @var \Magento\CatalogInventory\Model\Spi\StockStateProviderInterface
     */
    protected $stockStateProvider;

    /**
     * Core event manager proxy
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager = null;

    /**
     * @var \Magento\ImportExport\Model\Import\Config
     */
    protected $_importConfig;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceFactory
     */
    protected $_resourceFactory;

    /**
     * @var \Magento\Eav\Model\Resource\Entity\Attribute\Set\CollectionFactory
     */
    protected $_setColFactory;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product\Type\Factory
     */
    protected $_productTypeFactory;

    /**
     * @var \Magento\Catalog\Model\Resource\Product\LinkFactory
     */
    protected $_linkFactory;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Proxy\ProductFactory
     */
    protected $_proxyProdFactory;

    /**
     * @var \Magento\CatalogImportExport\Model\Import\UploaderFactory
     */
    protected $_uploaderFactory;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $_mediaDirectory;

    /**
     * @var \Magento\CatalogInventory\Model\Resource\Stock\ItemFactory
     */
    protected $_stockResItemFac;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $_localeDate;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var \Magento\Indexer\Model\IndexerRegistry
     */
    protected $indexerRegistry;

    /**
     * @var Product\StoreResolver
     */
    protected $storeResolver;

    /**
     * @var Product\SkuProcessor
     */
    protected $skuProcessor;

    /**
     * @var Product\CategoryProcessor
     */
    protected $categoryProcessor;

    /**
     * Instance of product tax class processor.
     *
     * @var Product\TaxClassProcessor
     */
    protected $taxClassProcessor;

    /**
     * @var Product\Validator
     */
    protected $validator;

    /**
     * Array of validated rows.
     *
     * @var array
     */
    protected $validatedRows;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $_logger;

    /**
     * {@inheritdoc}
     */
    protected $masterAttributeCode = 'sku';

    /**
     * Instance of catalog product factory.
     *
     * @var \Magento\Catalog\Model\ProductFactory $catalogProductFactory
     */
    protected $catalogProductFactory;

    /**
     * @var ObjectRelationProcessor
     */
    protected $objectRelationProcessor;

    /**
     * @var TransactionManagerInterface
     */
    protected $transactionManager;

    /**
     * Flag for replace operation.
     *
     * @var null
     */
    protected $_replaceFlag = null;

    /**
     * @param \Magento\Framework\Json\Helper\Data $jsonHelper
     * @param \Magento\ImportExport\Helper\Data $importExportData
     * @param \Magento\ImportExport\Model\Resource\Import\Data $importData
     * @param \Magento\Eav\Model\Config $config
     * @param \Magento\Framework\App\Resource $resource
     * @param \Magento\ImportExport\Model\Resource\Helper $resourceHelper
     * @param \Magento\Framework\Stdlib\String $string
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\CatalogInventory\Model\Spi\StockStateProviderInterface $stockStateProvider
     * @param \Magento\Catalog\Helper\Data $catalogData
     * @param \Magento\ImportExport\Model\Import\Config $importConfig
     * @param Proxy\Product\ResourceFactory $resourceFactory
     * @param Product\OptionFactory $optionFactory
     * @param \Magento\Eav\Model\Resource\Entity\Attribute\Set\CollectionFactory $setColFactory
     * @param Product\Type\Factory $productTypeFactory
     * @param \Magento\Catalog\Model\Resource\Product\LinkFactory $linkFactory
     * @param Proxy\ProductFactory $proxyProdFactory
     * @param UploaderFactory $uploaderFactory
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\CatalogInventory\Model\Resource\Stock\ItemFactory $stockResItemFac
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param DateTime $dateTime
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Indexer\Model\IndexerRegistry $indexerRegistry
     * @param Product\StoreResolver $storeResolver
     * @param Product\SkuProcessor $skuProcessor
     * @param Product\CategoryProcessor $categoryProcessor
     * @param Product\TaxClassProcessor $taxClassProcessor
     * @param Product\Validator $validator
     * @param \Magento\Catalog\Model\ProductFactory $catalogProductFactory
     * @param array $data
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Magento\ImportExport\Helper\Data $importExportData,
        \Magento\ImportExport\Model\Resource\Import\Data $importData,
        \Magento\Eav\Model\Config $config,
        \Magento\Framework\App\Resource $resource,
        \Magento\ImportExport\Model\Resource\Helper $resourceHelper,
        \Magento\Framework\Stdlib\String $string,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\CatalogInventory\Model\Spi\StockStateProviderInterface $stockStateProvider,
        \Magento\Catalog\Helper\Data $catalogData,
        \Magento\ImportExport\Model\Import\Config $importConfig,
        \Magento\CatalogImportExport\Model\Import\Proxy\Product\ResourceFactory $resourceFactory,
        \Magento\CatalogImportExport\Model\Import\Product\OptionFactory $optionFactory,
        \Magento\Eav\Model\Resource\Entity\Attribute\Set\CollectionFactory $setColFactory,
        \Magento\CatalogImportExport\Model\Import\Product\Type\Factory $productTypeFactory,
        \Magento\Catalog\Model\Resource\Product\LinkFactory $linkFactory,
        \Magento\CatalogImportExport\Model\Import\Proxy\ProductFactory $proxyProdFactory,
        \Magento\CatalogImportExport\Model\Import\UploaderFactory $uploaderFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\CatalogInventory\Model\Resource\Stock\ItemFactory $stockResItemFac,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        DateTime $dateTime,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Indexer\Model\IndexerRegistry $indexerRegistry,
        Product\StoreResolver $storeResolver,
        Product\SkuProcessor $skuProcessor,
        Product\CategoryProcessor $categoryProcessor,
        Product\Validator $validator,
        \Magento\Catalog\Model\ProductFactory $catalogProductFactory,
        ObjectRelationProcessor $objectRelationProcessor,
        TransactionManagerInterface $transactionManager,
        Product\TaxClassProcessor $taxClassProcessor,
        array $data = []
    ) {
        $this->_eventManager = $eventManager;
        $this->stockRegistry = $stockRegistry;
        $this->stockConfiguration = $stockConfiguration;
        $this->stockStateProvider = $stockStateProvider;
        $this->_catalogData = $catalogData;
        $this->_importConfig = $importConfig;
        $this->_resourceFactory = $resourceFactory;
        $this->_setColFactory = $setColFactory;
        $this->_productTypeFactory = $productTypeFactory;
        $this->_linkFactory = $linkFactory;
        $this->_proxyProdFactory = $proxyProdFactory;
        $this->_uploaderFactory = $uploaderFactory;
        $this->_mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $this->_stockResItemFac = $stockResItemFac;
        $this->_localeDate = $localeDate;
        $this->dateTime = $dateTime;
        $this->indexerRegistry = $indexerRegistry;
        $this->_logger = $logger;
        $this->storeResolver = $storeResolver;
        $this->skuProcessor = $skuProcessor;
        $this->categoryProcessor = $categoryProcessor;
        $this->validator = $validator;
        $this->objectRelationProcessor = $objectRelationProcessor;
        $this->transactionManager = $transactionManager;
        $this->catalogProductFactory = $catalogProductFactory;
        $this->taxClassProcessor = $taxClassProcessor;
        parent::__construct($jsonHelper, $importExportData, $importData, $config, $resource, $resourceHelper, $string);
        $this->_optionEntity = isset(
            $data['option_entity']
        ) ? $data['option_entity'] : $optionFactory->create(
            ['data' => ['product_entity' => $this]]
        );

        $this->_initAttributeSets()
            ->_initTypeModels()
            ->_initSkus();
        $this->validator->init();
    }

    /**
     * Check one attribute. Can be overridden in child.
     *
     * @param string $attrCode Attribute code
     * @param array $attrParams Attribute params
     * @param array $rowData Row data
     * @param int $rowNum
     *
     * @return boolean
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function isAttributeValid($attrCode, array $attrParams, array $rowData, $rowNum)
    {
        switch ($attrParams['type']) {
            case 'varchar':
                $val = $this->string->cleanString($rowData[$attrCode]);
                $valid = $this->string->strlen($val) < self::DB_MAX_VARCHAR_LENGTH;
                break;
            case 'decimal':
                $val = trim($rowData[$attrCode]);
                $valid = (string)(double)$val === $val;
                break;
            case 'select':
            case 'multiselect':
                $valid = isset($attrParams['options'][strtolower($rowData[$attrCode])]);
                break;
            case 'int':
                $val = trim($rowData[$attrCode]);
                $valid = (string)(int)$val === $val;
                break;
            case 'datetime':
                $val = trim($rowData[$attrCode]);
                $valid = strtotime($val) !== false;
                break;
            case 'text':
                $val = $this->string->cleanString($rowData[$attrCode]);
                $valid = $this->string->strlen($val) < self::DB_MAX_TEXT_LENGTH;
                break;
            default:
                $valid = true;
                break;
        }

        if (!$valid) {
            $this->addRowError(__("Please correct the value for '%s'."), $rowNum, $attrCode);
        } elseif (!empty($attrParams['is_unique'])) {
            if (isset($this->_uniqueAttributes[$attrCode][$rowData[$attrCode]]) && ($this->_uniqueAttributes[$attrCode][$rowData[$attrCode]] != $rowData[self::COL_SKU])) {
                $this->addRowError(__("Duplicate Unique Attribute for '%s'"), $rowNum, $attrCode);
                return false;
            }
            $this->_uniqueAttributes[$attrCode][$rowData[$attrCode]] = $rowData[self::COL_SKU];
        }
        return (bool)$valid;
    }

    /**
     * Retrieve instance of product custom options import entity
     *
     * @return \Magento\CatalogImportExport\Model\Import\Product\Option
     */
    public function getOptionEntity()
    {
        return $this->_optionEntity;
    }

    /**
     * Multiple value separator getter.
     *
     * @return string
     */
    public function getMultipleValueSeparator()
    {
        if (!empty($this->_parameters[\Magento\ImportExport\Model\Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR])) {
            return $this->_parameters[\Magento\ImportExport\Model\Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR];
        }
        return self::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR;
    }

    /**
     * Retrieve id of media gallery attribute.
     *
     * @return int
     */
    public function getMediaGalleryAttributeId()
    {
        if (!$this->_media_gallery_attribute_id) {
            /** @var $resource \Magento\CatalogImportExport\Model\Import\Proxy\Product\Resource */
            $resource = $this->_resourceFactory->create();
            $this->_media_gallery_attribute_id = $resource->getAttribute(self::MEDIA_GALLERY_ATTRIBUTE_CODE)->getId();
        }
        return $this->_media_gallery_attribute_id;
    }

    /**
     * Set import parameters
     *
     * @param array $params
     * @return $this
     */
    public function setParameters(array $params)
    {
        parent::setParameters($params);
        $this->getOptionEntity()->setParameters($params);

        return $this;
    }

    /**
     * Delete products for replacement.
     *
     * @return $this
     */
    public function deleteProductsForReplacement()
    {
        $this->setParameters(array('behavior' => \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE));
        $this->_deleteProducts();

        return $this;
    }

    /**
     * Delete products.
     *
     * @return $this
     * @throws \Exception
     */
    protected function _deleteProducts()
    {
        $productEntityTable = $this->_resourceFactory->create()->getEntityTable();

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $idToDelete = [];

            foreach ($bunch as $rowNum => $rowData) {
                if ($this->validateRow($rowData, $rowNum) && self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
                    $idToDelete[] = $this->_oldSku[$rowData[self::COL_SKU]]['entity_id'];
                }
            }
            if ($idToDelete) {
                $this->transactionManager->start($this->_connection);
                try {
                    $this->objectRelationProcessor->delete(
                        $this->transactionManager,
                        $this->_connection,
                        $productEntityTable,
                        $this->_connection->quoteInto('entity_id IN (?)', $idToDelete),
                        ['entity_id' => $idToDelete]
                    );
                    $this->transactionManager->commit();
                } catch (\Exception $e) {
                    $this->transactionManager->rollBack();
                    throw $e;
                }
                $this->_eventManager->dispatch('catalog_product_import_bunch_delete_after', ['adapter' => $this, 'bunch' => $bunch]);
            }
        }
        return $this;
    }

    /**
     * Create Product entity from raw data.
     *
     * @throws \Exception
     * @return bool Result of operation.
     */
    protected function _importData()
    {
        if (\Magento\ImportExport\Model\Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            $this->_deleteProducts();
        } elseif (\Magento\ImportExport\Model\Import::BEHAVIOR_REPLACE == $this->getBehavior()) {
            $this->_replaceFlag = true;
            $this->_replaceProducts();
        } else {
            $this->_saveProductsData();
        }
        $this->_eventManager->dispatch('catalog_product_import_finish_before', ['adapter' => $this]);
        return true;
    }

    /**
     * Replace imported products.
     *
     * @return $this
     */
    protected function _replaceProducts()
    {
        $this->deleteProductsForReplacement();
        $this->_oldSku = $this->skuProcessor->reloadOldSkus()->getOldSkus();
        $this->_validatedRows = null;
        $this->setParameters(array('behavior' => \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND));
        $this->_saveProductsData();

        return $this;
    }

    /**
     * Save products data.
     *
     * @return $this
     */
    protected function _saveProductsData()
    {
        $this->_saveProducts();
        foreach ($this->_productTypeModels as $productTypeModel) {
            $productTypeModel->saveData();
        }
        $this->_saveLinks();
        $this->_saveStockItem();
        if ($this->_replaceFlag) {
            $this->getOptionEntity()->clearProductsSkuToId();
        }
        $this->getOptionEntity()->importData();

        return $this;
    }

    /**
     * Initialize attribute sets code-to-id pairs.
     *
     * @return $this
     */
    protected function _initAttributeSets()
    {
        foreach ($this->_setColFactory->create()->setEntityTypeFilter($this->_entityTypeId) as $attributeSet) {
            $this->_attrSetNameToId[$attributeSet->getAttributeSetName()] = $attributeSet->getId();
            $this->_attrSetIdToName[$attributeSet->getId()] = $attributeSet->getAttributeSetName();
        }
        return $this;
    }

    /**
     * Initialize existent product SKUs.
     *
     * @return $this
     */
    protected function _initSkus()
    {
        $this->skuProcessor->setTypeModels($this->_productTypeModels);
        $this->_oldSku = $this->skuProcessor->getOldSkus();
        return $this;
    }

    /**
     * Initialize product type models.
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _initTypeModels()
    {
        $productTypes = $this->_importConfig->getEntityTypes($this->getEntityTypeCode());
        foreach ($productTypes as $productTypeName => $productTypeConfig) {
            $params = [$this, $productTypeName];
            if (!($model = $this->_productTypeFactory->create($productTypeConfig['model'], ['params' => $params]))
            ) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Entity type model \'%1\' is not found', $productTypeConfig['model'])
                );
            }
            if (!$model instanceof \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __(
                        'Entity type model must be an instance of '
                        . 'Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType'
                    )
                );
            }
            if ($model->isSuitable()) {
                $this->_productTypeModels[$productTypeName] = $model;
            }
            $this->_fields_map = array_merge($this->_fields_map, $model->getCustomFieldsMapping());
            $this->_specialAttributes = array_merge($this->_specialAttributes, $model->getParticularAttributes());
        }
        // remove doubles
        $this->_specialAttributes = array_unique($this->_specialAttributes);

        return $this;
    }

    /**
     * Set valid attribute set and product type to rows with all scopes
     * to ensure that existing products doesn't changed.
     *
     * @param array $rowData
     * @return array
     */
    protected function _prepareRowForDb(array $rowData)
    {
        $rowData = $this->_customFieldsMapping($rowData);

        $rowData = parent::_prepareRowForDb($rowData);

        static $lastSku = null;

        if (\Magento\ImportExport\Model\Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            return $rowData;
        }

        $lastSku = $rowData[self::COL_SKU];

        if (isset($this->_oldSku[$lastSku])) {
            $newSku = $this->skuProcessor->getNewSku($lastSku);
            $rowData[self::COL_ATTR_SET] = $newSku['attr_set_code'];
            $rowData[self::COL_TYPE] = $newSku['type_id'];
        }

        return $rowData;
    }

    /**
     * Gather and save information about product links.
     * Must be called after ALL products saving done.
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _saveLinks()
    {
        $resource = $this->_linkFactory->create();
        $mainTable = $resource->getMainTable();
        $positionAttrId = [];
        $nextLinkId = $this->_resourceHelper->getNextAutoincrement($mainTable);
        $adapter = $this->_connection;

        // pre-load 'position' attributes ID for each link type once
        foreach ($this->_linkNameToId as $linkName => $linkId) {
            $select = $adapter->select()->from(
                $resource->getTable('catalog_product_link_attribute'),
                ['id' => 'product_link_attribute_id']
            )->where(
                'link_type_id = :link_id AND product_link_attribute_code = :position'
            );
            $bind = [':link_id' => $linkId, ':position' => 'position'];
            $positionAttrId[$linkId] = $adapter->fetchOne($select, $bind);
        }
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $productIds = [];
            $linkRows = [];
            $positionRows = [];

            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }

                $sku = $rowData[self::COL_SKU];

                foreach ($this->_linkNameToId as $linkName => $linkId) {
                    $productId = $this->skuProcessor->getNewSku($sku)['entity_id'];
                    $productIds[] = $productId;
                    if (isset($rowData[$linkName . 'sku'])) {
                        $linkSkus = explode($this->getMultipleValueSeparator(), $rowData[$linkName . 'sku']);

                        foreach ($linkSkus as $linkedSku) {
                            $linkedSku = trim($linkedSku);
                            if ((!is_null(
                                        $this->skuProcessor->getNewSku($linkedSku)
                                    ) || isset(
                                        $this->_oldSku[$linkedSku]
                                    )) && $linkedSku != $sku
                            ) {
                                $newSku = $this->skuProcessor->getNewSku($linkedSku);
                                if (!empty($newSku)) {
                                    $linkedId = $newSku['entity_id'];
                                } else {
                                    $linkedId = $this->_oldSku[$linkedSku]['entity_id'];
                                }

                                if ($linkedId == null) {
                                    // Import file links to a SKU which is skipped for some reason, which leads to a "NULL"
                                    // link causing fatal errors.
                                    $this->_logger->critical(
                                        new \Exception(
                                            sprintf(
                                                'WARNING: Orphaned link skipped: From SKU %s (ID %d) to SKU %s, ' .
                                                'Link type id: %d',
                                                $sku,
                                                $productId,
                                                $linkedSku,
                                                $linkId
                                            )
                                        )
                                    );
                                    continue;
                                }

                                $linkKey = "{$productId}-{$linkedId}-{$linkId}";

                                if (!isset($linkRows[$linkKey])) {
                                    $linkRows[$linkKey] = [
                                        'link_id' => $nextLinkId,
                                        'product_id' => $productId,
                                        'linked_product_id' => $linkedId,
                                        'link_type_id' => $linkId,
                                    ];
                                    if (!empty($rowData[$linkName . 'position'])) {
                                        $positionRows[] = [
                                            'link_id' => $nextLinkId,
                                            'product_link_attribute_id' => $positionAttrId[$linkId],
                                            'value' => $rowData[$linkName . 'position'],
                                        ];
                                    }
                                    $nextLinkId++;
                                }
                            }
                        }
                    }
                }
            }
            if (\Magento\ImportExport\Model\Import::BEHAVIOR_APPEND != $this->getBehavior() && $productIds) {
                $adapter->delete($mainTable, $adapter->quoteInto('product_id IN (?)', array_unique($productIds)));
            }
            if ($linkRows) {
                $adapter->insertOnDuplicate($mainTable, $linkRows, ['link_id']);
            }
            if ($positionRows) {
                // process linked product positions
                $adapter->insertOnDuplicate($resource->getAttributeTypeTable('int'), $positionRows, ['value']);
            }
        }
        return $this;
    }

    /**
     * Save product attributes.
     *
     * @param array $attributesData
     * @return $this
     */
    protected function _saveProductAttributes(array $attributesData)
    {
        foreach ($attributesData as $tableName => $skuData) {
            $tableData = [];
            $where = [];
            foreach ($skuData as $sku => $attributes) {
                $productId = $this->skuProcessor->getNewSku($sku)['entity_id'];

                foreach ($attributes as $attributeId => $storeValues) {
                    foreach ($storeValues as $storeId => $storeValue) {
                        $tableData[] = [
                            'entity_id' => $productId,
                            'attribute_id' => $attributeId,
                            'store_id' => $storeId,
                            'value' => $storeValue,
                        ];
                    }
                    /*
                    If the store based values are not provided for a particular store,
                    we default to the default scope values.
                    In this case, remove all the existing store based values stored in the table.
                    */
                    $where[] = $this->_connection->quoteInto(
                            '(store_id NOT IN (?)',
                            array_keys($storeValues)
                        ) . $this->_connection->quoteInto(
                            ' AND attribute_id = ?',
                            $attributeId
                        ) . $this->_connection->quoteInto(
                            ' AND entity_id = ?)',
                            $productId
                        );
                    if (count($where) >= self::ATTRIBUTE_DELETE_BUNCH) {
                        $this->_connection->delete($tableName, implode(' OR ', $where));
                        $where = [];
                    }
                }
            }
            if (!empty($where)) {
                $this->_connection->delete($tableName, implode(' OR ', $where));
            }
            $this->_connection->insertOnDuplicate($tableName, $tableData, ['value']);
        }
        return $this;
    }

    /**
     * Save product categories.
     *
     * @param array $categoriesData
     * @return $this
     */
    protected function _saveProductCategories(array $categoriesData)
    {
        static $tableName = null;

        if (!$tableName) {
            $tableName = $this->_resourceFactory->create()->getProductCategoryTable();
        }
        if ($categoriesData) {
            $categoriesIn = [];
            $delProductId = [];

            foreach ($categoriesData as $delSku => $categories) {
                $productId = $this->skuProcessor->getNewSku($delSku)['entity_id'];
                $delProductId[] = $productId;

                foreach (array_keys($categories) as $categoryId) {
                    $categoriesIn[] = ['product_id' => $productId, 'category_id' => $categoryId, 'position' => 1];
                }
            }
            if (\Magento\ImportExport\Model\Import::BEHAVIOR_APPEND != $this->getBehavior()) {
                $this->_connection->delete(
                    $tableName,
                    $this->_connection->quoteInto('product_id IN (?)', $delProductId)
                );
            }
            if ($categoriesIn) {
                $this->_connection->insertOnDuplicate($tableName, $categoriesIn, ['position']);
            }
        }
        return $this;
    }

    /**
     * Update and insert data in entity table.
     *
     * @param array $entityRowsIn Row for insert
     * @param array $entityRowsUp Row for update
     * @return $this
     */
    protected function _saveProductEntity(array $entityRowsIn, array $entityRowsUp)
    {
        static $entityTable = null;

        if (!$entityTable) {
            $entityTable = $this->_resourceFactory->create()->getEntityTable();
        }
        if ($entityRowsUp) {
            $this->_connection->insertOnDuplicate($entityTable, $entityRowsUp, ['updated_at']);
        }
        if ($entityRowsIn) {
            $this->_connection->insertMultiple($entityTable, $entityRowsIn);

            $newProducts = $this->_connection->fetchPairs(
                $this->_connection->select()->from(
                    $entityTable,
                    ['sku', 'entity_id']
                )->where(
                    'sku IN (?)',
                    array_keys($entityRowsIn)
                )
            );
            foreach ($newProducts as $sku => $newId) {
                // fill up entity_id for new products
                $this->skuProcessor->setNewSkuData($sku, 'entity_id', $newId);
            }
        }
        return $this;
    }

    /**
     * Gather and save information about product entities.
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _saveProducts()
    {
        /** @var $resource \Magento\CatalogImportExport\Model\Import\Proxy\Product\Resource */
        $resource = $this->_resourceFactory->create();
        $priceIsGlobal = $this->_catalogData->isPriceGlobal();
        $productLimit = null;
        $productsQty = null;

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityRowsIn = [];
            $entityRowsUp = [];
            $attributes = [];
            $websites = [];
            $categories = [];
            $tierPrices = [];
            $groupPrices = [];
            $mediaGallery = [];
            $uploadedGalleryFiles = [];
            $previousType = null;
            $prevAttributeSet = null;

            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->validateRow($rowData, $rowNum)) {
                    continue;
                }
                $rowScope = $this->getRowScope($rowData);

                $rowSku = $rowData[self::COL_SKU];

                if (null === $rowSku) {
                    $this->_rowsToSkip[$rowNum] = true;
                    // skip rows when SKU is NULL
                    continue;
                } elseif (self::SCOPE_STORE == $rowScope) {
                    // set necessary data from SCOPE_DEFAULT row
                    $rowData[self::COL_TYPE] = $this->skuProcessor->getNewSku($rowSku)['type_id'];
                    $rowData['attribute_set_id'] = $this->skuProcessor->getNewSku($rowSku)['attr_set_id'];
                    $rowData[self::COL_ATTR_SET] = $this->skuProcessor->getNewSku($rowSku)['attr_set_code'];
                }

                // 1. Entity phase
                if (isset($this->_oldSku[$rowSku])) {
                    // existing row
                    $entityRowsUp[] = [
                        'updated_at' => (new \DateTime())->format(DateTime::DATETIME_PHP_FORMAT),
                        'entity_id' => $this->_oldSku[$rowSku]['entity_id'],
                    ];
                } else {
                    if (!$productLimit || $productsQty < $productLimit) {
                        $entityRowsIn[$rowSku] = [
                            'attribute_set_id' => $this->skuProcessor->getNewSku($rowSku)['attr_set_id'],
                            'type_id' => $this->skuProcessor->getNewSku($rowSku)['type_id'],
                            'sku' => $rowSku,
                            'has_options' => isset($rowData['has_options']) ? $rowData['has_options'] : 0,
                            'created_at' => (new \DateTime())->format(DateTime::DATETIME_PHP_FORMAT),
                            'updated_at' => (new \DateTime())->format(DateTime::DATETIME_PHP_FORMAT),
                        ];
                        $productsQty++;
                    } else {
                        $rowSku = null;
                        // sign for child rows to be skipped
                        $this->_rowsToSkip[$rowNum] = true;
                        continue;
                    }
                }

                // 2. Product-to-Website phase
                if (!empty($rowData[self::COL_PRODUCT_WEBSITES])) {
                    $websites[$rowSku][$this->storeResolver->getWebsiteCodeToId($rowData[self::COL_PRODUCT_WEBSITES])] = true;
                }

                // 3. Categories phase
                $categoriesString = empty($rowData[self::COL_CATEGORY]) ? '' : $rowData[self::COL_CATEGORY];
                if (!empty($categoriesString)) {
                    foreach ($this->categoryProcessor->upsertCategories($categoriesString) as $categoryId) {
                        $categories[$rowSku][$categoryId] = true;
                    }
                }

                // 4.1. Tier prices phase
                if (!empty($rowData['_tier_price_website'])) {
                    $tierPrices[$rowSku][] = [
                        'all_groups' => $rowData['_tier_price_customer_group'] == self::VALUE_ALL,
                        'customer_group_id' => $rowData['_tier_price_customer_group'] ==
                        self::VALUE_ALL ? 0 : $rowData['_tier_price_customer_group'],
                        'qty' => $rowData['_tier_price_qty'],
                        'value' => $rowData['_tier_price_price'],
                        'website_id' => self::VALUE_ALL == $rowData['_tier_price_website'] ||
                        $priceIsGlobal ? 0 : $this->storeResolver->getWebsiteCodeToId($rowData['_tier_price_website']),
                    ];
                }

                // 4.2. Group prices phase
                if (!empty($rowData['_group_price_website'])) {
                    $groupPrices[$rowSku][] = [
                        'all_groups' => $rowData['_group_price_customer_group'] == self::VALUE_ALL,
                        'customer_group_id' => $rowData['_group_price_customer_group'] ==
                        self::VALUE_ALL ? 0 : $rowData['_group_price_customer_group'],
                        'value' => $rowData['_group_price_price'],
                        'website_id' => self::VALUE_ALL == $rowData['_group_price_website'] ||
                        $priceIsGlobal ? 0 : $this->storeResolver->getWebsiteCodeToId($rowData['_group_price_website']),
                    ];
                }

                // 5. Media gallery phase
                $mediaGalleryImages = array();
                $mediaGalleryLabels = array();
                if (!empty($rowData[self::COL_MEDIA_IMAGE])) {
                    $mediaGalleryImages = explode($this->getMultipleValueSeparator(), $rowData[self::COL_MEDIA_IMAGE]);
                    $mediaGalleryLabels = isset($rowData['_media_image_label']) ? explode($this->getMultipleValueSeparator(), $rowData['_media_image_label']) : array();
                    if (count($mediaGalleryLabels) > count($mediaGalleryImages)) {
                        $mediaGalleryLabels = array_slice($mediaGalleryLabels, 0, count($mediaGalleryImages));
                    } elseif (count($mediaGalleryLabels) < count($mediaGalleryImages)) {
                        $mediaGalleryLabels = array_pad($mediaGalleryLabels, count($mediaGalleryImages), '');
                    }
                }

                foreach ($this->_imagesArrayKeys as $imageCol) {
                    if (!empty($rowData[$imageCol]) && ($imageCol != self::COL_MEDIA_IMAGE) && !in_array($rowData[$imageCol], $mediaGalleryImages)) {
                        $mediaGalleryImages[] = $rowData[$imageCol];
                        $mediaGalleryLabels[] = isset($rowData[$imageCol . '_label']) ? $rowData[$imageCol . '_label'] : '';
                    }
                }

                $rowData[self::COL_MEDIA_IMAGE] = array();

                foreach ($mediaGalleryImages as $mediaImage) {
                    if (!array_key_exists($mediaImage, $uploadedGalleryFiles)) {
                        $uploadedGalleryFiles[$mediaImage] = $this->_uploadMediaFiles(
                            trim($mediaImage)
                        );
                    }
                    $rowData[self::COL_MEDIA_IMAGE][] = $uploadedGalleryFiles[$mediaImage];
                }

                foreach ($this->_imagesArrayKeys as $imageCol) {
                    if (!empty($rowData[$imageCol]) && ($imageCol != self::COL_MEDIA_IMAGE)) {
                        $rowData[$imageCol] = $uploadedGalleryFiles[$rowData[$imageCol]];
                    }
                }

                if (!empty($rowData[self::COL_MEDIA_IMAGE]) && is_array($rowData[self::COL_MEDIA_IMAGE])) {
                    $position = 0;

                    foreach($rowData[self::COL_MEDIA_IMAGE] as $media_image) {
                        $mediaGallery[$rowSku][] = [
                            'attribute_id' => $this->getMediaGalleryAttributeId(),
                            'label' => isset($mediaGalleryLabels[$position]) ? $mediaGalleryLabels[$position] : '',
                            'position' => $position++,
                            'disabled' => '',
                            'value' => $media_image,
                        ];
                    }
                }

                // 6. Attributes phase
                $rowStore = (self::SCOPE_STORE == $rowScope)
                    ? $this->storeResolver->getStoreCodeToId($rowData[self::COL_STORE])
                    : 0;
                $productType = isset($rowData[self::COL_TYPE]) ? $rowData[self::COL_TYPE] : null;
                if (!is_null($productType)) {
                    $previousType = $productType;
                }
                if (isset($rowData[self::COL_ATTR_SET])) {
                    $prevAttributeSet = $rowData[self::COL_ATTR_SET];
                }
                if (self::SCOPE_NULL == $rowScope) {
                    // for multiselect attributes only
                    if (!is_null($prevAttributeSet)) {
                        $rowData[self::COL_ATTR_SET] = $prevAttributeSet;
                    }
                    if (is_null($productType) && !is_null($previousType)) {
                        $productType = $previousType;
                    }
                    if (is_null($productType)) {
                        continue;
                    }
                }

                $productTypeModel = $this->_productTypeModels[$productType];
                if (!empty($rowData['tax_class_name'])) {
                    $rowData['tax_class_id'] = $this->taxClassProcessor->upsertTaxClass($rowData['tax_class_name'], $productTypeModel);
                }

                if ($this->getBehavior() == \Magento\ImportExport\Model\Import::BEHAVIOR_APPEND ||
                    empty($rowData[self::COL_SKU])
                ) {
                    $rowData = $productTypeModel->clearEmptyData($rowData);
                }

                $rowData = $productTypeModel->prepareAttributesWithDefaultValueForSave(
                    $rowData,
                    !isset($this->_oldSku[$rowSku])
                );
                $product = $this->_proxyProdFactory->create(['data' => $rowData]);

                foreach ($rowData as $attrCode => $attrValue) {
                    if (!isset($this->_attributeCache[$attrCode])) {
                        $this->_attributeCache[$attrCode] = $resource->getAttribute($attrCode);
                    }
                    $attribute = $this->_attributeCache[$attrCode];

                    if ('multiselect' != $attribute->getFrontendInput() && self::SCOPE_NULL == $rowScope) {
                        // skip attribute processing for SCOPE_NULL rows
                        continue;
                    }
                    $attrId = $attribute->getId();
                    $backModel = $attribute->getBackendModel();
                    $attrTable = $attribute->getBackend()->getTable();
                    $storeIds = [0];

                    if ('datetime' == $attribute->getBackendType() && strtotime($attrValue)) {
                        $attrValue = (new \DateTime())->setTimestamp(strtotime($attrValue));
                        $attrValue = $attrValue->format(DateTime::DATETIME_PHP_FORMAT);
                    } elseif ($backModel) {
                        $attribute->getBackend()->beforeSave($product);
                        $attrValue = $product->getData($attribute->getAttributeCode());
                    }
                    if (self::SCOPE_STORE == $rowScope) {
                        if (self::SCOPE_WEBSITE == $attribute->getIsGlobal()) {
                            // check website defaults already set
                            if (!isset($attributes[$attrTable][$rowSku][$attrId][$rowStore])) {
                                $storeIds = $this->storeResolver->getStoreIdToWebsiteStoreIds($rowStore);
                            }
                        } elseif (self::SCOPE_STORE == $attribute->getIsGlobal()) {
                            $storeIds = [$rowStore];
                        }
                        if (!isset($this->_oldSku[$rowSku])) {
                            $storeIds[] = 0;
                        }
                    }
                    foreach ($storeIds as $storeId) {
                        if ('multiselect' == $attribute->getFrontendInput()) {
                            if (!isset($attributes[$attrTable][$rowSku][$attrId][$storeId])) {
                                $attributes[$attrTable][$rowSku][$attrId][$storeId] = '';
                            } else {
                                $attributes[$attrTable][$rowSku][$attrId][$storeId] .= ',';
                            }
                            $attributes[$attrTable][$rowSku][$attrId][$storeId] .= $attrValue;
                        } else {
                            $attributes[$attrTable][$rowSku][$attrId][$storeId] = $attrValue;
                        }
                    }
                    // restore 'backend_model' to avoid 'default' setting
                    $attribute->setBackendModel($backModel);
                }
            }

            $this->_saveProductEntity(
                $entityRowsIn,
                $entityRowsUp
            )->_saveProductWebsites(
                $websites
            )->_saveProductCategories(
                $categories
            )->_saveProductTierPrices(
                $tierPrices
            )->_saveProductGroupPrices(
                $groupPrices
            )->_saveMediaGallery(
                $mediaGallery
            )->_saveProductAttributes(
                $attributes
            );

            $this->_eventManager->dispatch('catalog_product_import_bunch_save_after', ['adapter' => $this, 'bunch' => $bunch]);
        }
        return $this;
    }

    /**
     * Save product tier prices.
     *
     * @param array $tierPriceData
     * @return $this
     */
    protected function _saveProductTierPrices(array $tierPriceData)
    {
        static $tableName = null;

        if (!$tableName) {
            $tableName = $this->_resourceFactory->create()->getTable('catalog_product_entity_tier_price');
        }
        if ($tierPriceData) {
            $tierPriceIn = [];
            $delProductId = [];

            foreach ($tierPriceData as $delSku => $tierPriceRows) {
                $productId = $this->skuProcessor->getNewSku($delSku)['entity_id'];
                $delProductId[] = $productId;

                foreach ($tierPriceRows as $row) {
                    $row['entity_id'] = $productId;
                    $tierPriceIn[] = $row;
                }
            }
            if (\Magento\ImportExport\Model\Import::BEHAVIOR_APPEND != $this->getBehavior()) {
                $this->_connection->delete(
                    $tableName,
                    $this->_connection->quoteInto('entity_id IN (?)', $delProductId)
                );
            }
            if ($tierPriceIn) {
                $this->_connection->insertOnDuplicate($tableName, $tierPriceIn, ['value']);
            }
        }
        return $this;
    }

    /**
     * Save product group prices.
     *
     * @param array $groupPriceData
     * @return $this
     */
    protected function _saveProductGroupPrices(array $groupPriceData)
    {
        static $tableName = null;

        if (!$tableName) {
            $tableName = $this->_resourceFactory->create()->getTable('catalog_product_entity_group_price');
        }
        if ($groupPriceData) {
            $groupPriceIn = [];
            $delProductId = [];

            foreach ($groupPriceData as $delSku => $groupPriceRows) {
                $productId = $this->skuProcessor->getNewSku($delSku)['entity_id'];
                $delProductId[] = $productId;

                foreach ($groupPriceRows as $row) {
                    $row['entity_id'] = $productId;
                    $groupPriceIn[] = $row;
                }
            }
            if (\Magento\ImportExport\Model\Import::BEHAVIOR_APPEND != $this->getBehavior()) {
                $this->_connection->delete(
                    $tableName,
                    $this->_connection->quoteInto('entity_id IN (?)', $delProductId)
                );
            }
            if ($groupPriceIn) {
                $this->_connection->insertOnDuplicate($tableName, $groupPriceIn, ['value']);
            }
        }
        return $this;
    }

    /**
     * Returns an object for upload a media files
     *
     * @return \Magento\CatalogImportExport\Model\Import\Uploader
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getUploader()
    {
        if (is_null($this->_fileUploader)) {
            $this->_fileUploader = $this->_uploaderFactory->create();

            $this->_fileUploader->init();

            $dirConfig = DirectoryList::getDefaultConfig();
            $dirAddon = $dirConfig[DirectoryList::MEDIA][DirectoryList::PATH];

            $DS = DIRECTORY_SEPARATOR;

            if (!empty($this->_parameters[\Magento\ImportExport\Model\Import::FIELD_NAME_IMG_FILE_DIR])) {
                $tmpPath = $this->_parameters[\Magento\ImportExport\Model\Import::FIELD_NAME_IMG_FILE_DIR];
            } else {
                $tmpPath = $dirAddon . $DS . $this->_mediaDirectory->getRelativePath('import');
            }

            if (!$this->_fileUploader->setTmpDir($tmpPath)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('File directory \'%1\' is not readable.', $tmpPath)
                );
            }
            $destinationDir = "catalog/product";
            $destinationPath = $dirAddon . $DS . $this->_mediaDirectory->getRelativePath($destinationDir);

            $this->_mediaDirectory->create($destinationDir);
            if (!$this->_fileUploader->setDestDir($destinationPath)) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('File directory \'%1\' is not writable.', $destinationPath)
                );
            }
        }
        return $this->_fileUploader;
    }

    /**
     * Uploading files into the "catalog/product" media folder.
     * Return a new file name if the same file is already exists.
     *
     * @param string $fileName
     * @return string
     */
    protected function _uploadMediaFiles($fileName)
    {
        try {
            $res = $this->_getUploader()->move($fileName);
            return $res['file'];
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Save product media gallery.
     *
     * @param array $mediaGalleryData
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _saveMediaGallery(array $mediaGalleryData)
    {
        if (empty($mediaGalleryData)) {
            return $this;
        }

        static $mediaGalleryTableName = null;
        static $mediaValueTableName = null;
        static $productId = null;

        if (!$mediaGalleryTableName) {
            $mediaGalleryTableName = $this->_resourceFactory->create()->getTable(
                'catalog_product_entity_media_gallery'
            );
        }

        if (!$mediaValueTableName) {
            $mediaValueTableName = $this->_resourceFactory->create()->getTable(
                'catalog_product_entity_media_gallery_value'
            );
        }
        $productIds = [];
        $multiInsertData = [];
        foreach ($mediaGalleryData as $productSku => $mediaGalleryRows) {
            $productId = $this->skuProcessor->getNewSku($productSku)['entity_id'];
            $productIds[] = $productId;
            $insertedGalleryImgs = [];

            if (\Magento\ImportExport\Model\Import::BEHAVIOR_APPEND != $this->getBehavior()) {
                $this->_connection->delete(
                    $mediaGalleryTableName,
                    $this->_connection->quoteInto('entity_id IN (?)', $productId)
                );
            }
            foreach ($mediaGalleryRows as $insertValue) {
                if (!in_array($insertValue['value'], $insertedGalleryImgs)) {
                    $valueArr = [
                        'attribute_id' => $insertValue['attribute_id'],
                        'entity_id' => $productId,
                        'value' => $insertValue['value'],
                    ];
                    $multiInsertData[] = $valueArr;
                    $insertedGalleryImgs[] = $insertValue['value'];
                }
            }
        }
        $this->_connection->insertOnDuplicate($mediaGalleryTableName, $multiInsertData, ['entity_id']);
        $multiInsertData = [];
        $newMediaValues = $this->_connection->fetchAssoc(
            $this->_connection->select()->from(
                $mediaGalleryTableName,
                ['value_id', 'value', 'entity_id']
            )->where(
                'entity_id IN (?)',
                $productIds
            )
        );
        foreach ($mediaGalleryData as $productSku => $mediaGalleryRows) {
            foreach ($mediaGalleryRows as $insertValue) {
                foreach ($newMediaValues as $value_id => $values) {
                    if ($values['value'] == $insertValue['value']) {
                        $insertValue['value_id'] = $value_id;
                        $insertValue['entity_id'] = $values['entity_id'];
                        unset($newMediaValues[$value_id]);
                        break;
                    }
                }
                if (isset($insertValue['value_id'])) {
                    $valueArr = [
                        'value_id' => $insertValue['value_id'],
                        'store_id' => \Magento\Store\Model\Store::DEFAULT_STORE_ID,
                        'entity_id' => $insertValue['entity_id'],
                        'label' => $insertValue['label'],
                        'position' => $insertValue['position'],
                        'disabled' => $insertValue['disabled'],
                    ];
                    $multiInsertData[] = $valueArr;
                }
            }
        }
        try {
            $this->_connection->insertOnDuplicate($mediaValueTableName, $multiInsertData, ['value_id']);
        } catch (\Exception $e) {
            $this->_connection->delete(
                $mediaGalleryTableName,
                $this->_connection->quoteInto('value_id IN (?)', $newMediaValues)
            );
        }

        return $this;
    }


    /**
     * Save product websites.
     *
     * @param array $websiteData
     * @return $this
     */
    protected function _saveProductWebsites(array $websiteData)
    {
        static $tableName = null;

        if (!$tableName) {
            $tableName = $this->_resourceFactory->create()->getProductWebsiteTable();
        }
        if ($websiteData) {
            $websitesData = [];
            $delProductId = [];

            foreach ($websiteData as $delSku => $websites) {
                $productId = $this->skuProcessor->getNewSku($delSku)['entity_id'];
                $delProductId[] = $productId;

                foreach (array_keys($websites) as $websiteId) {
                    $websitesData[] = ['product_id' => $productId, 'website_id' => $websiteId];
                }
            }
            if (\Magento\ImportExport\Model\Import::BEHAVIOR_APPEND != $this->getBehavior()) {
                $this->_connection->delete(
                    $tableName,
                    $this->_connection->quoteInto('product_id IN (?)', $delProductId)
                );
            }
            if ($websitesData) {
                $this->_connection->insertOnDuplicate($tableName, $websitesData);
            }
        }
        return $this;
    }

    /**
     * Stock item saving.
     *
     * @return $this
     */
    protected function _saveStockItem()
    {
        $indexer = $this->indexerRegistry->get('catalog_product_category');
        /** @var $stockResource \Magento\CatalogInventory\Model\Resource\Stock\Item */
        $stockResource = $this->_stockResItemFac->create();
        $entityTable = $stockResource->getMainTable();
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $stockData = [];
            $productIdsToReindex = [];
            // Format bunch to stock data rows
            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }

                $row = [];
                $row['product_id'] = $this->skuProcessor->getNewSku($rowData[self::COL_SKU])['entity_id'];
                $productIdsToReindex[] = $row['product_id'];

                $row['website_id'] = $this->stockConfiguration->getDefaultWebsiteId();
                $row['stock_id'] = $this->stockRegistry->getStock($row['website_id'])->getStockId();

                $stockItemDo = $this->stockRegistry->getStockItem($row['product_id'], $row['website_id']);
                $existStockData = $stockItemDo->getData();

                $row = array_merge(
                    $this->defaultStockData,
                    array_intersect_key($existStockData, $this->defaultStockData),
                    array_intersect_key($rowData, $this->defaultStockData),
                    $row
                );

                if ($this->stockConfiguration->isQty(
                    $this->skuProcessor->getNewSku($rowData[self::COL_SKU])['type_id']
                )) {
                    $stockItemDo->setData($row);
                    $row['is_in_stock'] = $this->stockStateProvider->verifyStock($stockItemDo);
                    if ($this->stockStateProvider->verifyNotification($stockItemDo)) {
                        $row['low_stock_date'] = $this->_localeDate->date(null, null, false)
                            ->format('Y-m-d H:i:s');
                    }
                    $row['stock_status_changed_auto'] =
                        (int) !$this->stockStateProvider->verifyStock($stockItemDo);
                } else {
                    $row['qty'] = 0;
                }
                $stockData[] = $row;
            }

            // Insert rows
            if (!empty($stockData)) {
                $this->_connection->insertOnDuplicate($entityTable, $stockData);
            }

            if ($productIdsToReindex) {
                $indexer->reindexList($productIdsToReindex);
            }
        }
        return $this;
    }

    /**
     * Attribute set ID-to-name pairs getter.
     *
     * @return array
     */
    public function getAttrSetIdToName()
    {
        return $this->_attrSetIdToName;
    }

    /**
     * DB connection getter.
     *
     * @return \Magento\Framework\DB\Adapter\Pdo\Mysql
     */
    public function getConnection()
    {
        return $this->_connection;
    }

    /**
     * EAV entity type code getter.
     *
     * @abstract
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'catalog_product';
    }

    /**
     * New products SKU data.
     *
     * @return array
     */
    public function getNewSku()
    {
        return $this->skuProcessor->getNewSku();
    }

    /**
     * Get next bunch of validated rows.
     *
     * @return array|null
     */
    public function getNextBunch()
    {
        return $this->_dataSourceModel->getNextBunch();
    }

    /**
     * Existing products SKU getter.
     *
     * @return array
     */
    public function getOldSku()
    {
        return $this->_oldSku;
    }

    /**
     * Obtain scope of the row from row data.
     *
     * @param array $rowData
     * @return int
     */
    public function getRowScope(array $rowData)
    {
        if (empty($rowData[self::COL_SKU])) {
            return self::SCOPE_NULL;
        }
        if (empty($rowData[self::COL_STORE])) {
            return self::SCOPE_DEFAULT;
        }
        return self::SCOPE_STORE;
    }

    /**
     * Validate data row.
     *
     * @param array $rowData
     * @param int $rowNum
     * @return boolean
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function validateRow(array $rowData, $rowNum)
    {
        // SKU is remembered through all product rows
        static $sku = null;
        if (isset($this->_validatedRows[$rowNum])) {
            // check that row is already validated
            return !isset($this->_invalidRows[$rowNum]);
        }
        $this->_validatedRows[$rowNum] = true;

        $rowScope = $this->getRowScope($rowData);

        // BEHAVIOR_DELETE use specific validation logic
        if (\Magento\ImportExport\Model\Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            if (self::SCOPE_DEFAULT == $rowScope && !isset($this->_oldSku[$rowData[self::COL_SKU]])) {
                $this->addRowError(ValidatorInterface::ERROR_SKU_NOT_FOUND_FOR_DELETE, $rowNum);
                return false;
            }
            return true;
        }

        if (!$this->validator->isValid($rowData)) {
            foreach ($this->validator->getMessages() as $message) {
                $this->addRowError($message, $rowNum);
            }
        }

        $sku = $rowData[self::COL_SKU];
        if (null === $sku) {
            $this->addRowError(ValidatorInterface::ERROR_SKU_IS_EMPTY, $rowNum);
        } elseif (false === $sku) {
            $this->addRowError(ValidatorInterface::ERROR_ROW_IS_ORPHAN, $rowNum);
        } elseif (self::SCOPE_STORE == $rowScope
            && !$this->storeResolver->getStoreCodeToId($rowData[self::COL_STORE])
        ) {
            $this->addRowError(ValidatorInterface::ERROR_INVALID_STORE, $rowNum);
        }

        // SKU is specified, row is SCOPE_DEFAULT, new product block begins
        $this->_processedEntitiesCount++;

        $sku = $rowData[self::COL_SKU];

        if (isset($this->_oldSku[$sku])) {
            // can we get all necessary data from existent DB product?
            // check for supported type of existing product
            if (isset($this->_productTypeModels[$this->_oldSku[$sku]['type_id']])) {
                $this->skuProcessor->addNewSku(
                    $sku,
                    [
                        'entity_id' => $this->_oldSku[$sku]['entity_id'],
                        'type_id' => $this->_oldSku[$sku]['type_id'],
                        'attr_set_id' => $this->_oldSku[$sku]['attr_set_id'],
                        'attr_set_code' => $this->_attrSetIdToName[$this->_oldSku[$sku]['attr_set_id']],
                    ]
                );
            } else {
                $this->addRowError(ValidatorInterface::ERROR_TYPE_UNSUPPORTED, $rowNum);
                // child rows of legacy products with unsupported types are orphans
                $sku = false;
            }
        } else {
            // validate new product type and attribute set
            if (!isset($rowData[self::COL_TYPE]) || !isset($this->_productTypeModels[$rowData[self::COL_TYPE]])) {
                $this->addRowError(ValidatorInterface::ERROR_INVALID_TYPE, $rowNum);
            } elseif (!isset(
                $rowData[self::COL_ATTR_SET]
            ) || !isset(
                $this->_attrSetNameToId[$rowData[self::COL_ATTR_SET]]
            )
            ) {
                $this->addRowError(ValidatorInterface::ERROR_INVALID_ATTR_SET, $rowNum);
            } elseif (is_null($this->skuProcessor->getNewSku($sku))) {
                $this->skuProcessor->addNewSku(
                    $sku,
                    [
                        'entity_id' => null,
                        'type_id' => $rowData[self::COL_TYPE],
                        'attr_set_id' => $this->_attrSetNameToId[$rowData[self::COL_ATTR_SET]],
                        'attr_set_code' => $rowData[self::COL_ATTR_SET],
                    ]
                );
            }
            if (isset($this->_invalidRows[$rowNum])) {
                // mark SCOPE_DEFAULT row as invalid for future child rows if product not in DB already
                $sku = false;
            }
        }

        if (!isset($this->_invalidRows[$rowNum])) {
            $newSku = $this->skuProcessor->getNewSku($sku);
            // set attribute set code into row data for followed attribute validation in type model
            $rowData[self::COL_ATTR_SET] = $newSku['attr_set_code'];

            $rowAttributesValid = $this->_productTypeModels[$newSku['type_id']]->isRowValid(
                $rowData,
                $rowNum,
                !isset($this->_oldSku[$sku])
            );
            if (!$rowAttributesValid && self::SCOPE_DEFAULT == $rowScope) {
                // mark SCOPE_DEFAULT row as invalid for future child rows if product not in DB already
                $sku = false;
            }
        }
        // validate custom options
        $this->getOptionEntity()->validateRow($rowData, $rowNum);

        return !isset($this->_invalidRows[$rowNum]);
    }

    /**
     * Parse attributes names and values string to array.
     *
     * @param array $rowData
     *
     * @return array
     */
    private function _parseAdditionalAttributes($rowData)
    {
        if (empty($rowData['additional_attributes'])) {
            return $rowData;
        }

        $attributeNameValuePairs = explode($this->getMultipleValueSeparator(), $rowData['additional_attributes']);
        foreach ($attributeNameValuePairs as $attributeNameValuePair) {
            $nameAndValue = explode(self::PAIR_NAME_VALUE_SEPARATOR, $attributeNameValuePair);
            if (!empty($nameAndValue)) {
                $rowData[$nameAndValue[0]] = isset($nameAndValue[1]) ? $nameAndValue[1] : '';
            }
        }
        return $rowData;
    }

    /**
     * Set values in use_config_ fields.
     *
     * @param array $rowData
     *
     * @return array
     */
    private function _setStockUseConfigFieldsValues($rowData)
    {
        $use_config_fields = array();
        foreach ($rowData as $key => $value) {
            if (isset($this->defaultStockData[$key]) && isset($this->defaultStockData[self::INVENTORY_USE_CONFIG_PREFIX . $key]) && !empty($value)) {
                $use_config_fields[self::INVENTORY_USE_CONFIG_PREFIX . $key] = ($value == self::INVENTORY_USE_CONFIG) ? 1 : 0;
            }
        }
        $rowData = array_merge($rowData, $use_config_fields);
        return $rowData;
    }

    /**
     * Custom fields mapping for changed purposes of fields and field names.
     *
     * @param array $rowData
     *
     * @return array
     */
    private function _customFieldsMapping($rowData)
    {
        foreach ($this->_fields_map as $system_field_name => $file_field_name) {
            if (isset($rowData[$file_field_name])) {
                $rowData[$system_field_name] = $rowData[$file_field_name];
            }
        }

        $rowData = $this->_parseAdditionalAttributes($rowData);

        $rowData = $this->_setStockUseConfigFieldsValues($rowData);
        if (isset($rowData['status'])) {
            if (($rowData['status'] == \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) || $rowData['status'] == 'yes') {
                $rowData['status'] = \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED;
            } else {
                $rowData['status'] = \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED;
            }
        }
        return $rowData;
    }

    /**
     * Validate data rows and save bunches to DB
     *
     * @return $this
     */
    protected function _saveValidatedBunches()
    {
        $source = $this->_getSource();
        $source->rewind();
        while ($source->valid()) {
            if ($this->_errorsCount >= $this->_errorsLimit) {
                // errors limit check
                return $this;
            }
            $rowData = $source->current();

            $rowData = $this->_customFieldsMapping($rowData);

            $this->validateRow($rowData, $source->key());
            $source->next();
        }
        $this->getOptionEntity()->validateAmbiguousData();
        return parent::_saveValidatedBunches();
    }

    /**
     * Get array of affected products
     *
     * @return array
     */
    public function getAffectedProducts()
    {
        $products = [];
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }
                if ($product = $this->_populateToUrlGeneration($rowData)) {
                    $products[] = $product;
                }
            }
        }
        return $products;
    }

    /**
     * Create product model from imported data for URL rewrite purposes.
     *
     * @param $rowData
     *
     * @return \Magento\Framework\Model\AbstractModel|void
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function _populateToUrlGeneration($rowData)
    {
        $product = $this->catalogProductFactory->create();
        $newSku = $this->skuProcessor->getNewSku($rowData[self::COL_SKU]);
        if (empty($newSku) || !isset($newSku['entity_id'])) {
            return;
        }
        $rowData['entity_id'] = $newSku['entity_id'];
        $product->addData($rowData);
        return $product;
    }
}
