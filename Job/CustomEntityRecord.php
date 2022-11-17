<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile CustomEntityAkeneo to newer
 * versions in the future.
 *
 * @author    Dmytro Khrushch <dmytro.khrusch@smile-ukraine.com>
 * @copyright 2022 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
declare(strict_types = 1);

namespace Smile\CustomEntityAkeneo\Job;

use Akeneo\Connector\Helper\Authenticator;
use Akeneo\Connector\Helper\Config;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Helper\Output as OutputHelper;
use Akeneo\Connector\Helper\Store as StoreHelper;
use Akeneo\Connector\Job\Import;
use Akeneo\Connector\Model\Source\Attribute\Tables as AttributeTables;
use Exception;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filter\FilterManager;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntityAkeneo\Helper\Import\ReferenceEntity;
use Smile\CustomEntityAkeneo\Model\ConfigManager;
use Zend_Db_Exception;
use Zend_Db_Expr as Expr;
use Zend_Db_Statement_Exception;

/**
 * Custom entity record (reference record) import job.
 *
 * @category  Class
 * @package   Smile\CustomEntityAkeneo\Job
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomEntityRecord extends Import
{
    /**
     * #@+
     * Custom entity table.
     */
    const ENTITY_TABLE = 'smile_custom_entity';
    /**#@-*/

    /**
     * Import code.
     *
     * @var string $code
     */
    protected string $code = 'smile_custom_entity_record';

    /**
     * Import name.
     *
     * @var string $name
     */
    protected string $name = 'Smile Custom Entity Record';

    /**
     * Import config.
     *
     * @var ConfigManager
     */
    protected ConfigManager $configManager;

    /**
     * Cache type list.
     *
     * @var TypeListInterface
     */
    protected TypeListInterface $cacheTypeList;

    /**
     * Store helper.
     *
     * @var StoreHelper
     */
    protected StoreHelper $storeHelper;

    /**
     * Reference entity helper.
     *
     * @var ReferenceEntity
     */
    protected ReferenceEntity $referenceEntityHelper;

    /**
     * Attribute tables.
     *
     * @var AttributeTables
     */
    protected AttributeTables $attributeTables;

    /**
     * Filter manager.
     *
     * @var FilterManager
     */
    protected FilterManager $filterManager;

    /**
     * Entity default attributes.
     *
     * @var string[]
     */
    protected $defaultAttributes = [
        'label' => 'name',
        'image' => 'image'
    ];

    /**
     * Constructor.
     *
     * @param OutputHelper $outputHelper
     * @param ManagerInterface $eventManager
     * @param Authenticator $authenticator
     * @param Entities $entitiesHelper
     * @param Config $configHelper
     * @param ConfigManager $configManager
     * @param TypeListInterface $cacheTypeList
     * @param StoreHelper $storeHelper
     * @param ReferenceEntity $referenceEntityHelper
     * @param AttributeTables $attributeTables
     * @param array $data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        OutputHelper      $outputHelper,
        ManagerInterface  $eventManager,
        Authenticator     $authenticator,
        Entities          $entitiesHelper,
        Config            $configHelper,
        ConfigManager     $configManager,
        TypeListInterface $cacheTypeList,
        StoreHelper       $storeHelper,
        ReferenceEntity   $referenceEntityHelper,
        AttributeTables   $attributeTables,
        FilterManager     $filterManager,
        array             $data = []
    )
    {
        parent::__construct(
            $outputHelper,
            $eventManager,
            $authenticator,
            $entitiesHelper,
            $configHelper,
            $data
        );
        $this->configManager = $configManager;
        $this->cacheTypeList = $cacheTypeList;
        $this->storeHelper = $storeHelper;
        $this->referenceEntityHelper = $referenceEntityHelper;
        $this->attributeTables = $attributeTables;
        $this->filterManager = $filterManager;
    }

    /**
     * Create temporary table, load records and insert it in the temporary table.
     *
     * @return void
     *
     * @throws AlreadyExistsException
     * @throws Zend_Db_Exception
     * @throws Zend_Db_Statement_Exception
     */
    public function loadRecords(): void
    {
        $entities = $this->referenceEntityHelper->getEntitiesToImport();

        if (empty($entities)) {
            $this->jobExecutor->setMessage(__('No entities found'));
            $this->jobExecutor->afterRun(true);
            return;
        }

        $this->entitiesHelper->createTmpTable(
            ['code', 'entity'],
            $this->jobExecutor->getCurrentJob()->getCode()
        );
        $this->entitiesHelper->createTmpTable(
            ['record', 'attribute', 'locale', 'data'],
            'custom_entity_record_attribute'
        );

        $api = $this->akeneoClient->getReferenceEntityRecordApi();
        $recordsCount = 0;
        foreach ($entities as $entity) {
            $entityRecords = $api->all($entity);
            foreach ($entityRecords as $record) {
                $this->entitiesHelper->insertDataFromApi(
                    [
                        'code' => $record['code'],
                        'entity' => $entity
                    ],
                    $this->jobExecutor->getCurrentJob()->getCode()
                );
                foreach ($record['values'] as $attributeCode => $attributeValues) {
                    $attribute = $this->getAttributeCode($entity, $attributeCode);
                    foreach ($attributeValues as $attributeValue) {
                        $this->entitiesHelper->insertDataFromApi(
                            [
                                'record' => $record['code'],
                                'attribute' => $attribute,
                                'locale' => $attributeValue['locale'],
                                'data' => $attributeValue['data'],

                            ],
                            'custom_entity_record_attribute'
                        );
                    }
                }
                $recordsCount++;
            }
        }
        $this->jobExecutor->setMessage(
            __('%1 record(s) loaded', $recordsCount)
        );
    }

    /**
     * Check already imported records are still in Magento.
     *
     * @return void
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function checkEntities(): void
    {
        $connection = $this->entitiesHelper->getConnection();

        $adminLang = $this->storeHelper->getAdminLang();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $tmpAttributeTable = $this->entitiesHelper->getTableName('custom_entity_record_attribute');

        $selectNotEmpty = $connection->select()
            ->from($tmpAttributeTable, 'record')
            ->where('attribute = ?', 'name')
            ->where('locale = ?', $adminLang);

        $notEmptyNameRecords = array_column($connection->query($selectNotEmpty)->fetchAll(), 'record');
        $connection->delete(
            $tmpTable,
            ['code NOT IN (?)' => $notEmptyNameRecords]
        );
        $connection->delete(
            $tmpAttributeTable,
            ['record NOT IN (?)' => $notEmptyNameRecords]
        );

        if (!empty($notEmptyNameRecords)) {
            $this->jobExecutor->setAdditionalMessage(
                __('%1 record(s) was removed from the import due to an empty name for the default locale %2',
                    implode(", ", $notEmptyNameRecords),
                    $adminLang
                )
            );
        }

        $akeneoConnectorTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $entityTable = $this->entitiesHelper->getTable(self::ENTITY_TABLE);
        $deleteQuery = $connection->select()
            ->from(['ace' => $akeneoConnectorTable], null)
            ->joinLeft(
                ['sce' => $entityTable],
                "ace.entity_id = sce.entity_id",
                []
            )
            ->where("sce.entity_id IS NULL AND ace.import = 'smile_custom_entity_record'");

        $connection->query("DELETE ace $deleteQuery");
    }

    /**
     * Match code with entity
     *
     * @return void
     *
     * @throws Exception
     */
    public function matchEntities(): void
    {
        $this->entitiesHelper->matchEntity(
            'code',
            self::ENTITY_TABLE,
            'entity_id',
            $this->jobExecutor->getCurrentJob()->getCode()
        );
    }

    /**
     * Add entity type.
     *
     * @return void
     */
    public function updateEntityType(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');

        $connection->addColumn($tmpTable, '_attribute_set_id', 'text');

        $select = $connection->select()
            ->from(['tmp' => $tmpTable], ['entity'])
            ->joinLeft(['ace' => $entityTable], 'ace.code = tmp.entity', ['entity_id'])
            ->where('ace.import = ?', 'smile_custom_entity')
            ->group('tmp.entity');

        $entityTypes = $connection->fetchAll($select);
        foreach ($entityTypes as $type) {
            $connection->update(
                $tmpTable,
                [
                    '_attribute_set_id' => $type['entity_id']
                ],
                $connection->prepareSqlCondition('entity', $type['entity'])
            );
        }
    }

    /**
     * Create records.
     *
     * @return void
     */
    public function createEntities(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $table = $this->entitiesHelper->getTable(self::ENTITY_TABLE);

        $values = [
            'entity_id' => '_entity_id',
            'attribute_set_id' => '_attribute_set_id',
            'updated_at' => new Expr('now()'),
        ];

        $parents = $connection->select()->from($tmpTable, $values);

        $query = $connection->insertFromSelect(
            $parents,
            $table,
            array_keys($values),
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);
    }

    /**
     * Update column values for options.
     *
     * @return void
     */
    public function updateOption(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName('custom_entity_record_attribute');
        $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $attributeTable = $this->entitiesHelper->getTable('eav_attribute');
        $entityTypeTable = $this->entitiesHelper->getTable('eav_entity_type');

        // Update "select" options
        $select = $connection->select()
            ->from(['tmp' => $tmpTable], ['record', 'attribute', 'locale'])
            ->joinLeft(['ace' => $entityTable], 'ace.code = CONCAT(tmp.attribute,"-",tmp.data)', ['entity_id'])
            ->joinLeft(['ea' => $attributeTable], 'tmp.attribute = ea.attribute_code', ['frontend_input'])
            ->joinLeft(['eet' => $entityTypeTable], 'ea.entity_type_id = eet.entity_type_id', [])
            ->where('eet.entity_type_code = ?', CustomEntityInterface::ENTITY)
            ->where('ace.code IS NOT NULL')
            ->where('ea.frontend_input = "select"')
            ->where('ace.import = ?', 'smile_custom_entity_attribute_option');

        $options = $connection->fetchAll($select);
        foreach ($options as $option) {
            $connection->update(
                $tmpTable,
                [
                    'data' => $option['entity_id']
                ],
                [
                    'record = ?' => $option['record'],
                    'attribute = ?' => $option['attribute'],
                    $option['locale'] ? 'locale = "' . $option['locale'] . '"' : 'locale IS NULL'
                ]
            );
        }
    }

    /**
     * Update column values for multiselect attributes.
     *
     * @return void
     */
    public function updateMultiselectValues(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName('custom_entity_record_attribute');
        $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $attributeTable = $this->entitiesHelper->getTable('eav_attribute');
        $entityTypeTable = $this->entitiesHelper->getTable('eav_entity_type');

        $select = $connection->select()
            ->from(['tmp' => $tmpTable], ['record', 'attribute', 'data'])
            ->joinLeft(['ea' => $attributeTable], 'tmp.attribute = ea.attribute_code', [])
            ->joinLeft(['eet' => $entityTypeTable], 'ea.entity_type_id = eet.entity_type_id', [])
            ->where('eet.entity_type_code = ?', CustomEntityInterface::ENTITY)
            ->where('ea.frontend_input = "multiselect"');
        $attributes = $connection->fetchAll($select);

        foreach ($attributes as $attribute) {
            $values = explode(",", $attribute['data']);
            $attributeValues = [];
            foreach ($values as $value) {
                $attributeValues[] = $attribute['attribute'] . "-" . $value;
            }
            $select = $connection->select()
                ->from(['ace' => $entityTable], ['entity_id'])
                ->where('import = ?', "smile_custom_entity_attribute_option")
                ->where('code IN (?)', $attributeValues);
            $multiselectValue = $connection->fetchCol($select);
            $connection->update(
                $tmpTable,
                [
                    'data' => implode(",", $multiselectValue)
                ],
                [
                    'record = ?' => $attribute['record'],
                    'attribute = ?' => $attribute['attribute']
                ]
            );
        }
    }

    /**
     * Insert entity attribute values.
     *
     * @return void
     *
     * @throws LocalizedException
     */
    public function setValues(): void
    {

        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $tmpAttributeTable = $this->entitiesHelper->getTableName('custom_entity_record_attribute');
        $entityTypeId = $this->configHelper->getEntityTypeId(CustomEntityInterface::ENTITY);

        // Insert global attributes
        $select = $connection->select()
            ->from(['a' => $tmpAttributeTable], ['attribute', 'data'])
            ->joinLeft(['r' => $tmpTable], 'a.record = r.code', ['_entity_id']);
        $globalAttributes = $connection->fetchAll($select->where('locale IS NULL'));
        $this->setAttributesValue($globalAttributes, 0, $entityTypeId);

        // Insert values for each locale
        $locales = $this->storeHelper->getStores('lang');
        foreach ($locales as $lang => $stores) {
            $localeAttributes = $connection->fetchAll($select->reset('where')->where('locale = ?', $lang));
            if (!empty($localeAttributes)) {
                foreach ($stores as $store) {
                    $this->setAttributesValue($localeAttributes, $store['store_id'], $entityTypeId);
                }
            }
        }
    }

    /**
     * Update url key.
     *
     * @return void
     *
     * @throws LocalizedException
     */
    public function setUrlKey(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $tmpAttributeTable = $this->entitiesHelper->getTableName('custom_entity_record_attribute');
        $entityTypeId = $this->configHelper->getEntityTypeId(CustomEntityInterface::ENTITY);
        $urlAttribute = $this->referenceEntityHelper->getAttribute(
            CustomEntityInterface::URL_KEY,
            $entityTypeId
        );
        $urlValuesTable = $this->entitiesHelper->getTable(
            self::ENTITY_TABLE . '_' . $urlAttribute[AttributeInterface::BACKEND_TYPE]
        );

        $select = $connection->select()
            ->from(['a' => $tmpAttributeTable], ['attribute', 'data'])
            ->joinLeft(['r' => $tmpTable], 'a.record = r.code', ['_entity_id']);

        // Insert values for each locale
        $locales = $this->storeHelper->getStores('lang');
        foreach ($locales as $lang => $stores) {
            $select->reset('where')
                ->where('a.attribute = "name"')
                ->where('locale = ?', $lang);
            $localeAttributes = $connection->fetchAll($select);
            if (!empty($localeAttributes)) {
                foreach ($localeAttributes as $row) {
                    $url = $this->filterManager->translitUrl($row['data']);
                    foreach ($stores as $store) {
                        $connection->insertOnDuplicate(
                            $urlValuesTable,
                            [
                                'attribute_id' => $urlAttribute[AttributeInterface::ATTRIBUTE_ID],
                                'store_id' => $store['store_id'],
                                'value' => $url,
                                'entity_id' => $row['_entity_id'],
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * Set status for new records.
     *
     * @return void
     */
    public function setIsActiveValues(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $entityTypeId = $this->configHelper->getEntityTypeId(CustomEntityInterface::ENTITY);

        $isActiveAttribute = $this->referenceEntityHelper->getAttribute(
            CustomEntityInterface::IS_ACTIVE,
            $entityTypeId
        );

        $valuesTable = $this->entitiesHelper->getTable(
            self::ENTITY_TABLE . '_' . $isActiveAttribute[AttributeInterface::BACKEND_TYPE]
        );

        $values = [
            'attribute_id' => new Expr($isActiveAttribute[AttributeInterface::ATTRIBUTE_ID]),
            'store_id' => new Expr('0'),
            'value' => new Expr($this->configManager->getDefaultEntityStatus()),
            'entity_id' => '_entity_id',
        ];

        $select = $connection->select()->from($tmpTable, $values)->where('_is_new = 1');

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $valuesTable,
                array_keys($values),
                2
            )
        );
    }


    /**
     * Load and save media.
     *
     * @return void
     *
     * @throws AlreadyExistsException
     * @throws FileSystemException
     */
    public function importMedia(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpAttributeTable = $this->entitiesHelper->getTableName('custom_entity_record_attribute');
        $select = $connection->select()
            ->from(['a' => $tmpAttributeTable], ['attribute', 'data'])
            ->where('a.attribute = "image"');
        $images = $connection->fetchAll($select);
        if (empty($images)) {
            $this->jobExecutor->setMessage(__('No images to import'));
            $this->jobExecutor->afterRun(true);
            return;
        }
        $api = $this->akeneoClient->getReferenceEntityMediaFileApi();
        foreach ($images as $image) {
            $filePath = 'scoped_eav/entity/' . $image['data'];
            $binary = $api->download($image['data']);
            $imageContent = $binary->getBody()->getContents();
            $this->configHelper->saveMediaFile($filePath, $imageContent);
        }
    }

    /**
     * Drop temporary table.
     *
     * @return void
     */
    public function dropTable()
    {
        $this->entitiesHelper->dropTable($this->jobExecutor->getCurrentJob()->getCode());
    }

    /**
     * Clean cache.
     *
     * @return void
     */
    public function cleanCache(): void
    {
        $types = $this->configManager->getCacheTypeRecord();
        if (empty($types)) {
            $this->jobExecutor->setMessage(__('No cache cleaned'));
            return;
        }
        $cacheTypeLabels = $this->cacheTypeList->getTypeLabels();
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }
        $this->jobExecutor->setMessage(
            __('Cache cleaned for: %1', join(', ', array_intersect_key($cacheTypeLabels, array_flip($types))))
        );
    }

    /**
     * Create attribute code based on entity code.
     *
     * @param string $entity
     * @param string $attributeCode
     *
     * @return string
     */
    protected function getAttributeCode(string $entity, string $attributeCode): string
    {
        return (key_exists($attributeCode, $this->defaultAttributes))
            ? $this->defaultAttributes[$attributeCode]
            : $entity . '_' . $attributeCode;
    }

    /**
     * Insert attribute values.
     *
     * @param $attributes
     * @param $storeId
     * @param $entityTypeId
     *
     * @return void
     */
    protected function setAttributesValue($attributes, $storeId, $entityTypeId): void
    {
        $connection = $this->entitiesHelper->getConnection();

        foreach ($attributes as $row) {
            $attribute = $this->referenceEntityHelper->getAttribute(
                $row['attribute'],
                $entityTypeId
            );

            if (empty($attribute) || !isset($attribute[AttributeInterface::BACKEND_TYPE])
                || $attribute[AttributeInterface::BACKEND_TYPE] === 'static') {
                continue;
            }

            if ($attribute[AttributeInterface::FRONTEND_INPUT] === 'image') {
                $row['data'] = '/media/scoped_eav/entity/' . $row['data'];
            }

            $backendType = $attribute[AttributeInterface::BACKEND_TYPE];
            $table = $this->entitiesHelper->getTable(self::ENTITY_TABLE . '_' . $backendType);
            $connection->insertOnDuplicate(
                $table,
                [
                    'attribute_id' => $attribute[AttributeInterface::ATTRIBUTE_ID],
                    'store_id' => $storeId,
                    'value' => $row['data'],
                    'entity_id' => $row['_entity_id'],
                ],
            );
        }
    }
}
