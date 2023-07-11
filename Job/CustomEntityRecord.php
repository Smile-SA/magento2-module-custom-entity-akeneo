<?php

declare(strict_types=1);

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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomEntityRecord extends Import
{
    /**
     * Table names.
     */
    public const ENTITY_TABLE = 'smile_custom_entity';
    public const TMP_TABLE_ATTRIBUTE_VALUES = 'custom_entity_record_attribute';

    /**
     * Import code.
     */
    protected string $code = 'smile_custom_entity_record';

    /**
     * Import name.
     */
    protected string $name = 'Smile custom entity record';

    /**
     * @var string[]
     */
    protected array $defaultAttributes = [
        'label' => 'name',
        'image' => 'image',
        'description' => 'description',
    ];

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        OutputHelper $outputHelper,
        ManagerInterface $eventManager,
        Authenticator $authenticator,
        Entities $entitiesHelper,
        Config $configHelper,
        protected ConfigManager $configManager,
        protected TypeListInterface $cacheTypeList,
        protected StoreHelper $storeHelper,
        protected ReferenceEntity $referenceEntityHelper,
        protected AttributeTables $attributeTables,
        protected FilterManager $filterManager,
        array $data = []
    ) {
        parent::__construct(
            $outputHelper,
            $eventManager,
            $authenticator,
            $entitiesHelper,
            $configHelper,
            $data
        );
    }

    /**
     * Create temporary table, load records and insert it in the temporary table.
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
            ['entity', 'record', 'attribute', 'locale', 'data'],
            self::TMP_TABLE_ATTRIBUTE_VALUES
        );

        $api = $this->akeneoClient->getReferenceEntityRecordApi();
        $recordsCount = 0;
        foreach ($entities as $entity) {
            $entityRecords = $api->all($entity);
            foreach ($entityRecords as $record) {
                $this->entitiesHelper->insertDataFromApi(
                    [
                        'code' => $record['code'],
                        'akeneo_entity_code' => $entity . '-' . $record['code'],
                        'entity' => $entity,
                    ],
                    $this->jobExecutor->getCurrentJob()->getCode()
                );
                foreach ($record['values'] as $attributeCode => $attributeValues) {
                    $attribute = $this->getAttributeCode($entity, $attributeCode);
                    foreach ($attributeValues as $attributeValue) {
                        $this->entitiesHelper->insertDataFromApi(
                            [
                                'entity' => $entity,
                                'record' => $record['code'],
                                'attribute' => $attribute,
                                'locale' => $attributeValue['locale'],
                                'data' => $attributeValue['data'],

                            ],
                            self::TMP_TABLE_ATTRIBUTE_VALUES
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
     * @throws Zend_Db_Statement_Exception
     */
    public function checkEntities(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $adminLang = $this->storeHelper->getAdminLang();

        $selectNotEmpty = $connection->select()
            ->from($this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES), 'record')
            ->where('attribute = ?', 'name')
            ->where('locale = ?', $adminLang);

        $notEmptyNameRecords = array_column($connection->query($selectNotEmpty)->fetchAll(), 'record');
        $deletedRecords = $connection->delete(
            $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode()),
            ['code NOT IN (?)' => $notEmptyNameRecords]
        );
        $connection->delete(
            $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES),
            ['record NOT IN (?)' => $notEmptyNameRecords]
        );

        if (!empty($notEmptyNameRecords)) {
            $this->jobExecutor->setAdditionalMessage(
                __(
                    '%1 record(s) was removed from the import due to an empty name for the default locale %2',
                    $deletedRecords,
                    $adminLang
                )
            );
        }

        $akeneoConnectorTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $deleteQuery = $connection->deleteFromSelect(
            $connection->select()
                ->from(['ace' => $akeneoConnectorTable], null)
                ->joinLeft(
                    ['sce' => $this->entitiesHelper->getTable(self::ENTITY_TABLE)],
                    "ace.entity_id = sce.entity_id",
                    []
                )
                ->where("sce.entity_id IS NULL AND ace.import = 'smile_custom_entity_record'"),
            'ace'
        );

        $connection->query($deleteQuery);
    }

    /**
     * Match code with entity
     *
     * @throws Exception
     */
    public function matchEntities(): void
    {
        $this->entitiesHelper->matchEntity(
            'akeneo_entity_code',
            self::ENTITY_TABLE,
            'entity_id',
            $this->jobExecutor->getCurrentJob()->getCode()
        );
    }

    /**
     * Add entity type.
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
                    '_attribute_set_id' => $type['entity_id'],
                ],
                $connection->prepareSqlCondition('entity', $type['entity'])
            );
        }
    }

    /**
     * Create records.
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

        $records = $connection->select()->from($tmpTable, $values);

        $query = $connection->insertFromSelect(
            $records,
            $table,
            array_keys($values),
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);
    }

    /**
     * Update column values for options.
     */
    public function updateOption(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES);
        $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $attributeTable = $this->entitiesHelper->getTable('eav_attribute');
        $entityTypeTable = $this->entitiesHelper->getTable('eav_entity_type');

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
                    'data' => $option['entity_id'],
                ],
                [
                    'record = ?' => $option['record'],
                    'attribute = ?' => $option['attribute'],
                    $option['locale'] ? 'locale = "' . $option['locale'] . '"' : 'locale IS NULL',
                ]
            );
        }
    }

    /**
     * Update column values for multiselect attributes.
     */
    public function updateMultiselectValues(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES);
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
                    'data' => implode(",", $multiselectValue),
                ],
                [
                    'record = ?' => $attribute['record'],
                    'attribute = ?' => $attribute['attribute'],
                ]
            );
        }
    }

    /**
     * Update column values for reference entity attributes.
     */
    public function updateReferenceEntityValues(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES);
        $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $attributeTable = $this->entitiesHelper->getTable('eav_attribute');
        $entityTypeTable = $this->entitiesHelper->getTable('eav_entity_type');

        $select = $connection->select()
            ->from(
                ['tmp' => $tmpTable],
                ['record', 'attribute', 'data']
            )->joinLeft(
                ['ea' => $attributeTable],
                'tmp.attribute = ea.attribute_code',
                []
            )->joinLeft(
                ['eet' => $entityTypeTable],
                'ea.entity_type_id = eet.entity_type_id',
                []
            )->joinLeft(
                ['ace' => $entityTable],
                'ace.entity_id = ea.custom_entity_attribute_set_id',
                ['entity_code' => 'code']
            )->where(
                'eet.entity_type_code = ?',
                CustomEntityInterface::ENTITY
            )->where(
                'ea.custom_entity_attribute_set_id IS NOT NULL'
            );

        $attributes = $connection->fetchAll($select);
        $loadedOptions = [];
        foreach ($attributes as &$attribute) {
            if (!$attribute['data']) {
                continue;
            }
            $attributeValues = explode(',', $attribute['data']);
            $data = [];
            foreach ($attributeValues as $attributeValue) {
                $code = $attribute['entity_code'] . '-' . $attributeValue;
                if (!key_exists($code, $loadedOptions)) {
                    $optionIdSelect = $connection->select()
                        ->from(['e' => $entityTable], ['entity_id'])
                        ->where('e.code = ?', $code)
                        ->where('e.import = "smile_custom_entity_record"');
                    $loadedOptions[$code] = $connection->fetchOne($optionIdSelect);
                }
                $data[] = $loadedOptions[$code];
            }
            $attribute['data'] = implode(',', $data);

            $connection->update(
                $tmpTable,
                [
                    'data' => $attribute['data'],
                ],
                [
                    'record = ?' => $attribute['record'],
                    'attribute = ?' => $attribute['attribute'],
                ]
            );
        }
    }

    /**
     * Insert entity attribute values.
     *
     * @throws LocalizedException
     */
    public function setValues(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $tmpAttributeTable = $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES);
        $entityTypeId = (int) $this->configHelper->getEntityTypeId(CustomEntityInterface::ENTITY);

        // Insert global attributes
        $select = $connection->select()
            ->from(['a' => $tmpAttributeTable], ['attribute', 'data'])
            ->joinLeft(['r' => $tmpTable], 'a.record = r.code AND a.entity = r.entity', ['_entity_id']);
        $globalAttributes = $connection->fetchAll($select->where('locale IS NULL'));
        $this->setAttributesValue($globalAttributes, 0, $entityTypeId);

        // Insert values for each locale
        $locales = $this->storeHelper->getStores('lang');
        foreach ($locales as $lang => $stores) {
            $localeAttributes = $connection->fetchAll($select->reset('where')->where('locale = ?', $lang));
            if (!empty($localeAttributes)) {
                foreach ($stores as $store) {
                    $this->setAttributesValue($localeAttributes, (int) $store['store_id'], $entityTypeId);
                }
            }
        }
    }

    /**
     * Update url key.
     *
     * @throws LocalizedException
     */
    public function setUrlKey(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $tmpAttributeTable = $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES);
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
            'value' => new Expr((string) $this->configManager->getDefaultEntityStatus()),
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
     * @throws AlreadyExistsException
     * @throws FileSystemException
     */
    public function importMedia(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpAttributeTable = $this->entitiesHelper->getTableName(self::TMP_TABLE_ATTRIBUTE_VALUES);
        $select = $connection->select()
            ->from(['a' => $tmpAttributeTable], ['attribute', 'data'])
            ->where('a.attribute = "image"');
        $images = $connection->fetchAll($select);
        if (empty($images)) {
            $this->jobExecutor->setMessage(__('No images to import'));
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
     */
    public function dropTable(): void
    {
        $this->entitiesHelper->dropTable($this->jobExecutor->getCurrentJob()->getCode());
        $this->entitiesHelper->dropTable(self::TMP_TABLE_ATTRIBUTE_VALUES);
    }

    /**
     * Clean cache.
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
     */
    protected function getAttributeCode(string $entity, string $attributeCode): string
    {
        return key_exists($attributeCode, $this->defaultAttributes)
            ? $this->defaultAttributes[$attributeCode]
            : $entity . '_' . $attributeCode;
    }

    /**
     * Insert attribute values.
     */
    protected function setAttributesValue(array $attributes, int $storeId, int $entityTypeId): void
    {
        $connection = $this->entitiesHelper->getConnection();

        foreach ($attributes as $row) {
            $attribute = $this->referenceEntityHelper->getAttribute(
                $row['attribute'],
                $entityTypeId
            );

            if (
                empty($attribute) || !isset($attribute[AttributeInterface::BACKEND_TYPE])
                || $attribute[AttributeInterface::BACKEND_TYPE] === 'static'
            ) {
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
