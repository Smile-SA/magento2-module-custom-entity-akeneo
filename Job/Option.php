<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Job;

use Akeneo\Connector\Helper\Authenticator;
use Akeneo\Connector\Helper\Config as AkeneoConfig;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Helper\Output as OutputHelper;
use Akeneo\Connector\Helper\Store as StoreHelper;
use Akeneo\Connector\Job\Import;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntityAkeneo\Helper\Import\ReferenceEntity;
use Smile\CustomEntityAkeneo\Model\ConfigManager;
use Zend_Db_Exception;
use Zend_Db_Expr as Expr;
use Zend_Db_Statement_Exception;

/**
 * Custom entity attribute options job.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Option extends Import
{
    /**
     * Import code.
     */
    protected string $code = 'smile_custom_entity_attribute_option';

    /**
     * Import name.
     */
    protected string $name = 'Smile Custom Entity Attribute Option';

    /**
     * Attribute types that have options.
     */
    protected array $attributeTypesWithOption = [
        'single_option',
        'multiple_options'
    ];

    /**
     * Reference entity attribute types.
     */
    protected array $referenceEntityAttributes = [
        'reference_entity_single_link',
        'reference_entity_multiple_links'
    ];

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        OutputHelper $outputHelper,
        ManagerInterface $eventManager,
        Authenticator $authenticator,
        Entities $entitiesHelper,
        AkeneoConfig $akeneoConfig,
        protected ConfigManager $configManager,
        protected TypeListInterface $cacheTypeList,
        protected StoreHelper $storeHelper,
        protected ReferenceEntity $referenceEntityHelper,
        array $data = []
    ) {
        parent::__construct(
            $outputHelper,
            $eventManager,
            $authenticator,
            $entitiesHelper,
            $akeneoConfig,
            $data
        );
    }

    /**
     * Create temporary table, load options and insert it in the temporary table.
     *
     * @throws AlreadyExistsException
     * @throws Zend_Db_Exception
     * @throws Zend_Db_Statement_Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function loadOptions(): void
    {
        $adminLocaleCode = $this->storeHelper->getAdminLang();
        $adminLabelColumn = 'labels-' . $adminLocaleCode;
        $connection = $this->entitiesHelper->getConnection();
        $attributeApi = $this->akeneoClient->getReferenceEntityAttributeApi();
        $attributeOptionApi = $this->akeneoClient->getReferenceEntityAttributeOptionApi();
        $optionsCount = 0;

        $entities = $this->referenceEntityHelper->getEntitiesToImport();

        if (empty($entities)) {
            $this->jobExecutor->setMessage(__('No entities found'));
            $this->jobExecutor->afterRun(true);
            return;
        }

        $this->entitiesHelper->createTmpTable(
            ['code', $adminLabelColumn],
            $this->jobExecutor->getCurrentJob()->getCode()
        );

        foreach ($entities as $entityCode) {
            $attributeApiResult = $attributeApi->all((string) $entityCode);
            foreach ($attributeApiResult as $attribute) {
                $attributeOptions = [];

                // Process options for select, multiselect attributes.
                if (in_array($attribute['type'], $this->attributeTypesWithOption)) {
                    $attributeOptions = $attributeOptionApi->all((string) $entityCode, (string) $attribute['code']);
                }

                // Process options for reference entity attributes.
                if (in_array($attribute['type'], $this->referenceEntityAttributes)) {
                    $attributeOptions = $this->processReferenceEntitiesOption(
                        (string) $attribute['reference_entity_code'],
                        (string) $attribute['code']
                    );
                }

                foreach ($attributeOptions as $option) {
                    $option['attribute'] = $entityCode . '_' . $attribute['code'];
                    $this->entitiesHelper->insertDataFromApi(
                        $option,
                        $this->jobExecutor->getCurrentJob()->getCode()
                    );
                    $optionsCount++;
                }
            }
        }
        $this->jobExecutor->setAdditionalMessage(
            __('%1 option(s) found', $optionsCount)
        );

        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $select = $connection->select()->from(
            $tmpTable,
            [
                'label' => $adminLabelColumn,
                'code' => 'code',
                'attribute' => 'attribute',
            ]
        )->where('`' . $adminLabelColumn . '` IS NULL');

        $query = $connection->query($select);
        while (($row = $query->fetch())) {
            if (!isset($row['label']) || $row['label'] == null) {
                $connection->delete($tmpTable, ['code = ?' => $row['code'], 'attribute = ?' => $row['attribute']]);
                $this->jobExecutor->setAdditionalMessage(
                    __(
                        'The option %1 from attribute %2 was not imported
                        because it did not have a translation in admin store language : %3',
                        $row['code'],
                        $row['attribute'],
                        $adminLocaleCode
                    )
                );
            }
        }
    }

    /**
     * Check already imported entities are still in Magento.
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function checkEntities(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $akeneoConnectorTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $entityTable = $this->entitiesHelper->getTable('eav_attribute_option');
        $selectExistingEntities = $connection->select()->from($entityTable, 'option_id');
        $existingEntities = array_column($connection->query($selectExistingEntities)->fetchAll(), 'option_id');

        $connection->delete(
            $akeneoConnectorTable,
            ['import = ?' => 'smile_custom_entity_attribute_option', 'entity_id NOT IN (?)' => $existingEntities]
        );
    }

    /**
     * Match code with entity.
     */
    public function matchEntities(): void
    {
        // Clear entities with empty code
        $connection = $this->entitiesHelper->getConnection();
        $tableName = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $connection->delete($tableName, ['code = ?' => '']);

        // Connect akeneo entities code with magento options entity_id.
        $this->matchOptionsId();
        $this->matchNewOptionsId();
    }

    /**
     * Create/update options.
     */
    public function insertOptions(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $columns = [
            'option_id' => 'a._entity_id',
            'sort_order' => new Expr('"0"'),
        ];
        if ($connection->tableColumnExists($tmpTable, 'sort_order')) {
            $columns['sort_order'] = 'a.sort_order';
        }
        $options = $connection->select()->from(['a' => $tmpTable], $columns)->joinInner(
            ['b' => $this->entitiesHelper->getTable('akeneo_connector_entities')],
            'a.attribute = b.code AND b.import = "smile_custom_entity_attribute"',
            [
                'attribute_id' => 'b.entity_id',
            ]
        );
        $connection->query(
            $connection->insertFromSelect(
                $options,
                $this->entitiesHelper->getTable('eav_attribute_option'),
                ['option_id', 'sort_order', 'attribute_id'],
                1
            )
        );
    }

    /**
     * Create/update option values.
     *
     * @throws LocalizedException
     */
    public function insertValues(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $stores = $this->storeHelper->getStores('lang');
        $adminLang = $this->storeHelper->getAdminLang();
        foreach ($stores as $local => $data) {
            if (!$connection->tableColumnExists($tmpTable, 'labels-' . $local)) {
                continue;
            }
            foreach ($data as $store) {
                $value = $store['store_id'] == 0 ? 'labels-' . $adminLang : 'labels-' . $local;
                $options = $connection->select()->from(
                    ['a' => $tmpTable],
                    [
                        'option_id' => '_entity_id',
                        'store_id' => new Expr($store['store_id']),
                        'value' => $value,
                    ]
                )->joinInner(
                    ['b' => $this->entitiesHelper->getTable('akeneo_connector_entities')],
                    'a.attribute = b.code AND b.import = "smile_custom_entity_attribute"',
                    []
                )->where('`a`.`' . $value . '` IS NOT NULL ');
                $connection->query(
                    $connection->insertFromSelect(
                        $options,
                        $this->entitiesHelper->getTable('eav_attribute_option_value'),
                        ['option_id', 'store_id', 'value'],
                        1
                    )
                );
            }
        }
    }

    /**
     * Drop temporary table.
     */
    public function dropTable(): void
    {
        $this->entitiesHelper->dropTable($this->jobExecutor->getCurrentJob()->getCode());
    }

    /**
     * Clean cache.
     */
    public function cleanCache(): void
    {
        $types = $this->configManager->getCacheTypeAttribute();
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
     * Connect existing Magento options to Akeneo items.
     */
    protected function matchOptionsId(): void
    {
        $localeCode = $this->storeHelper->getAdminLang();
        $connection = $this->entitiesHelper->getConnection();
        $tableName = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $akeneoConnectorTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $entityTypeId = $this->configHelper->getEntityTypeId(CustomEntityInterface::ENTITY);

        $select = $connection->select()
            ->from($akeneoConnectorTable, ['entity_id' => 'entity_id'])
            ->where('import = ?', $this->jobExecutor->getCurrentJob()->getCode());

        $existingEntities = $connection->query($select)->fetchAll();
        $existingEntities = array_column($existingEntities, 'entity_id');

        $columnToSelect = ['label' => 't.labels-' . $localeCode, 'code' => 't.code', 'attribute' => 't.attribute'];
        $condition = '`labels-' . $localeCode . '` = e.value';

        $select = $connection->select()->from(
            ['t' => $tableName],
            $columnToSelect
        )->joinInner(
            ['e' => 'eav_attribute_option_value'],
            $condition,
            []
        )->joinInner(
            ['o' => 'eav_attribute_option'],
            'o.`option_id` = e.`option_id`',
            ['option_id']
        )->joinInner(
            ['a' => 'eav_attribute'],
            'o.`attribute_id` = a.`attribute_id` AND t.`attribute` = a.`attribute_code`',
            []
        )->where('e.store_id = ?', 0)->where('a.entity_type_id', $entityTypeId);

        $existingMagentoOptions = $connection->query($select)->fetchAll();
        $existingMagentoOptionIds = array_column($existingMagentoOptions, 'option_id');
        $entitiesToCreate = array_diff($existingMagentoOptionIds, $existingEntities);

        foreach ($entitiesToCreate as $entityToCreateKey => $entityOptionId) {
            $currentEntity = $existingMagentoOptions[$entityToCreateKey];
            $values = [
                'import' => $this->jobExecutor->getCurrentJob()->getCode(),
                'code' => $currentEntity['attribute'] . '-' . $currentEntity['code'],
                'entity_id' => $entityOptionId,
            ];
            $connection->insertOnDuplicate($akeneoConnectorTable, $values);
        }

        $select = $connection->select()
            ->from(['c' => $akeneoConnectorTable], ['_entity_id' => 'entity_id'])
            ->where('CONCAT(t.`attribute`, "-", t.`code`) = c.`code`')
            ->where('c.`import` = ?', $this->jobExecutor->getCurrentJob()->getCode());
        $update = $connection->updateFromSelect($select, ['t' => $tableName]);

        $connection->query($update);
    }

    /**
     * Set entity_id for new entities and update akeneo_connector_entities table with code and new entity_id.
     */
    protected function matchNewOptionsId(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tableName = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $akeneoConnectorTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $entityTable = $this->entitiesHelper->getTable('eav_attribute_option');

        // Set entity_id for new entities
        $query = $connection->query('SHOW TABLE STATUS LIKE "' . $entityTable . '"');
        $row = $query->fetch();

        $connection->query('SET @id = ' . (int) $row['Auto_increment']);
        $values = [
            '_entity_id' => new Expr('@id := @id + 1'),
            '_is_new' => new Expr('1'),
        ];
        $connection->update($tableName, $values, '_entity_id IS NULL');

        // Update akeneo_connector_entities table with code and new entity_id
        $select = $connection->select()->from(
            $tableName,
            [
                'import' => new Expr("'" . $this->jobExecutor->getCurrentJob()->getCode() . "'"),
                'code' => new Expr('CONCAT(`attribute`, "-", `code`)'),
                'entity_id' => '_entity_id',
            ]
        )->where('_is_new = ?', 1);

        $connection->query(
            $connection->insertFromSelect($select, $akeneoConnectorTable, ['import', 'code', 'entity_id'], 2)
        );

        // Update entity table auto increment
        $count = $connection->fetchOne(
            $connection->select()->from($tableName, [new Expr('COUNT(*)')])->where('_is_new = ?', 1)
        );

        if ($count) {
            $maxCode = $connection->fetchOne(
                $connection->select()->from($akeneoConnectorTable, new Expr('MAX(`entity_id`)'))->where(
                    'import = ?',
                    $this->jobExecutor->getCurrentJob()->getCode()
                )
            );
            $maxEntity = $connection->fetchOne(
                $connection->select()->from($entityTable, new Expr('MAX(`option_id`)'))
            );

            $connection->query(
            // phpcs:ignore
                'ALTER TABLE `' . $entityTable . '` AUTO_INCREMENT = ' . (max((int) $maxCode, (int) $maxEntity) + 1)
            );
        }
    }

    /**
     * Load and transform entities' records to options.
     */
    protected function processReferenceEntitiesOption(string $referenceCode, string $attributeCode): array
    {
        $options = [];
        $records = $this->akeneoClient->getReferenceEntityRecordApi()->all($referenceCode);
        foreach ($records as $record) {
            if (!isset($record['values']['label'])) {
                $message = __('No label found for reference entity record %1.', $record['code']);
                $this->jobExecutor->setAdditionalMessage($message);
                continue;
            }

            $option = [];
            $option['code'] = $record['code'];
            $option['attribute'] = $attributeCode;
            $labels = $record['values']['label'] ?? [];

            foreach ($labels as $label) {
                $option['labels'][$label['locale']] = $label['data'];
            }

            $options[] = $option;
        }

        return $options;
    }
}
