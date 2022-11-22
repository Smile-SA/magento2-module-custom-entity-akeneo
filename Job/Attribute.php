<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Job;

use Akeneo\Connector\Helper\Authenticator;
use Akeneo\Connector\Helper\Config as AkeneoConfigHelper;
use Akeneo\Connector\Helper\Import\Attribute as AttributeHelper;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Helper\Output as OutputHelper;
use Akeneo\Connector\Helper\Store as StoreHelper;
use Akeneo\Connector\Job\Import;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntityAkeneo\Helper\Import\ReferenceEntity;
use Smile\CustomEntityAkeneo\Model\ConfigManager;
use Zend_Db_Expr as Expr;
use Zend_Db_Statement_Exception;

/**
 * Custom entity attribute import job.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Attribute extends Import
{
    /**
     * #@+
     * Default attribute group name.
     */
    public const DEFAULT_ATTRIBUTE_SET_NAME = 'Akeneo';
    /**#@-*/

    /**
     * Import code.
     */
    protected string $code = 'smile_custom_entity_attribute';

    /**
     * Import name.
     */
    protected string $name = 'Smile Custom Entity Attribute';

    /**
     * Cache type list.
     */
    protected TypeListInterface $cacheTypeList;

    /**
     * Import config.
     */
    protected ConfigManager $configManager;

    /**
     * Attribute helper.
     */
    protected AttributeHelper $attributeHelper;

    /**
     * Eav config.
     */
    protected EavConfig $eavConfig;

    /**
     * Store helper.
     */
    protected StoreHelper $storeHelper;

    /**
     * Eav setup.
     */
    protected EavSetup $eavSetup;

    /**
     * Reference entity import helper.
     */
    protected ReferenceEntity $referenceEntityHelper;

    /**
     * Excluded attributes from import.
     *
     * @var string[]
     */
    protected array $excludedAttributes = [
        'code',
        'label',
        'image',
    ];

    /**
     * Constructor.
     *
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        OutputHelper       $outputHelper,
        ManagerInterface   $eventManager,
        Authenticator      $authenticator,
        Entities           $entitiesHelper,
        AkeneoConfigHelper $configHelper,
        TypeListInterface  $cacheTypeList,
        ConfigManager      $configManager,
        EavConfig          $eavConfig,
        AttributeHelper    $attributeHelper,
        StoreHelper        $storeHelper,
        EavSetup           $eavSetup,
        ReferenceEntity    $referenceEntityHelper,
        array              $data = []
    ) {
        parent::__construct(
            $outputHelper,
            $eventManager,
            $authenticator,
            $entitiesHelper,
            $configHelper,
            $data
        );
        $this->cacheTypeList = $cacheTypeList;
        $this->configManager = $configManager;
        $this->eavConfig = $eavConfig;
        $this->attributeHelper = $attributeHelper;
        $this->storeHelper      = $storeHelper;
        $this->eavSetup = $eavSetup;
        $this->referenceEntityHelper = $referenceEntityHelper;
    }

    /**
     * Create temporary table, load attributes data and insert in the temporary table.
     *
     * @throws AlreadyExistsException
     * @throws Zend_Db_Statement_Exception
     */
    public function loadAttributes(): void
    {
        $attributes = [];
        $api = $this->akeneoClient->getReferenceEntityAttributeApi();
        $entities = $this->referenceEntityHelper->getEntitiesToImport();
        foreach ($entities as $entityCode) {
            $apiResult = $api->all((string) $entityCode);
            foreach ($apiResult as $attribute) {
                if (!isset($attribute['code']) || in_array($attribute['code'], $this->excludedAttributes)) {
                    continue;
                }
                $attribute['code'] = $entityCode . '_' . $attribute['code'];
                $attribute['entity_type'] = $entityCode;
                $attributes[] = $attribute;
            }
        }

        if (empty($attributes)) {
            $this->jobExecutor->setMessage(__('No attributes to import'));
            $this->jobExecutor->afterRun(true);
            return;
        }

        try {
            $adminLabelColumn = 'labels-' . $this->storeHelper->getAdminLang();
            $this->entitiesHelper->createTmpTable(
                ['code', $adminLabelColumn],
                $this->jobExecutor->getCurrentJob()->getCode()
            );

            foreach ($attributes as $index => $attribute) {
                if (ctype_digit(substr($attribute['code'], 0, 1))) {
                    $this->jobExecutor->setAdditionalMessage(
                        __(
                            'The attribute %1 was not imported because it starts with a number.
                            Update it in Akeneo and retry.',
                            $attribute['code']
                        )
                    );
                    continue;
                }
                $attributeCode = $attribute['code'];
                $attribute['code'] = strtolower($attributeCode);
                $this->entitiesHelper->insertDataFromApi($attribute, $this->jobExecutor->getCurrentJob()->getCode());
            }
            $index++;

            $this->jobExecutor->setAdditionalMessage(
                __('%1 line(s) found', $index)
            );
        } catch (\Exception $e) {
            $this->jobExecutor->setMessage($e->getMessage());
            $this->jobExecutor->afterRun(true);
            return;
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
        $entityTable = $this->entitiesHelper->getTable('eav_attribute');
        $entityTypeTable = $this->entitiesHelper->getTable('eav_entity_type');

        $selectExistingEntities = $connection->select()
            ->from(['ea' => $entityTable], 'ea.attribute_id')
            ->joinLeft(['eet' => $entityTypeTable], 'ea.entity_type_id = eet.entity_type_id')
            ->where('eet.entity_type_code = ?', CustomEntityInterface::ENTITY);
        $existingEntities = array_column($connection->query($selectExistingEntities)->fetchAll(), 'attribute_id');
        $connection->delete(
            $akeneoConnectorTable,
            ['import = ?' => 'smile_custom_entity_attribute', 'entity_id NOT IN (?)' => $existingEntities]
        );
    }

    /**
     * Match code with entity.
     *
     * @throws LocalizedException
     */
    public function matchEntities(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $select = $connection->select()->from(
            $this->entitiesHelper->getTable('eav_attribute'),
            [
                'import' => new Expr('"smile_custom_entity_attribute"'),
                'code' => 'attribute_code',
                'entity_id' => 'attribute_id',
            ]
        )->where('entity_type_id = ?', $this->getEntityTypeId());

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->entitiesHelper->getTable('akeneo_connector_entities'),
                ['import', 'code', 'entity_id'],
                2
            )
        );

        $this->entitiesHelper->matchEntity(
            'code',
            'eav_attribute',
            'attribute_id',
            $this->jobExecutor->getCurrentJob()->getCode()
        );
    }

    /**
     * Match type with Magento logic.
     */
    public function matchType(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $columns = $this->attributeHelper->getSpecificColumns();

        foreach ($columns as $name => $def) {
            $connection->addColumn($tmpTable, $name, $def['type']);
        }

        $select = $connection->select()->from(
            $tmpTable,
            array_merge(
                ['_entity_id', 'type'],
                array_keys($columns)
            )
        );

        $data = $connection->fetchAssoc($select);

        foreach ($data as $id => $attribute) {
            $type = $this->attributeHelper->getType($attribute['type']);
            $connection->update($tmpTable, $type, ['_entity_id = ?' => $id]);
        }
    }

    /**
     * Match attribute set.
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function matchAttributeSet(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $entityTypeTable = $this->entitiesHelper->getTable('akeneo_connector_entities');

        $connection->addColumn($tmpTable, '_attribute_set_id', 'text');
        $importRelationsQuery = $connection->select()
            ->from(
                ['tmp' => $tmpTable],
                'code'
            )->joinLeft(
                ['ace' => $entityTypeTable],
                'tmp.entity_type = ace.code',
                ['attribute_set_id' => 'entity_id']
            )->where(
                $connection->prepareSqlCondition('import', ['like' => 'smile_custom_entity'])
            );
        $importRelations = $connection->query($importRelationsQuery)->fetchAll();
        foreach ($importRelations as $relation) {
            $connection->update(
                $tmpTable,
                [
                    '_attribute_set_id' => $relation['attribute_set_id'],
                ],
                $connection->prepareSqlCondition('code', $relation['code'])
            );
        }
    }

    /**
     * Create/update attributes.
     *
     * @throws LocalizedException
     * @throws Zend_Db_Statement_Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function addAttributes(): void
    {
        $columns = $this->attributeHelper->getSpecificColumns();
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());

        $adminLang = $this->storeHelper->getAdminLang();

        $adminLabelColumn = sprintf('labels-%s', $adminLang);
        $import = $connection->select()->from($tmpTable);
        $query = $connection->query($import);

        while (($row = $query->fetch())) {
            /* Verify attribute type if already present in Magento */
            $attributeFrontendInput = $connection->fetchOne(
                $connection->select()->from(
                    $this->entitiesHelper->getTable('eav_attribute'),
                    ['frontend_input']
                )->where('attribute_code = ?', $row['code'])->where('entity_type_id = ?', $this->getEntityTypeId())
            );

            if ($attributeFrontendInput && ($attributeFrontendInput !== $row['frontend_input'])) {
                    $message = __(
                        'The attribute %1 was skipped because its type is not the same between Akeneo and Magento.
                        Please delete it in Magento and try a new import',
                        $row['code']
                    );
                    $this->jobExecutor->setAdditionalMessage($message);
                    continue;
            }

            $values = [
                'attribute_id'   => $row['_entity_id'],
                'entity_type_id' => $this->getEntityTypeId(),
                'attribute_code' => $row['code'],
            ];
            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('eav_attribute'),
                $values,
                array_keys($values)
            );

            $values = [
                'attribute_id' => $row['_entity_id'],
            ];
            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('smile_custom_entity_eav_attribute'),
                $values,
                array_keys($values)
            );

            $frontendLabel = !empty($row[$adminLabelColumn]) ? $row[$adminLabelColumn] : "PIM (" . $row['code'] . ")";

            /* Retrieve attribute scope */
            $global = ScopedAttributeInterface::SCOPE_GLOBAL; // Global
            if ($row['value_per_locale']) {
                $global = ScopedAttributeInterface::SCOPE_STORE; // Website
            }
            if (!$row['value_per_locale'] && $row['value_per_channel']) {
                $global = ScopedAttributeInterface::SCOPE_WEBSITE; // Store View
            }

            $data = [
                'entity_type_id' => $this->getEntityTypeId(),
                'attribute_code' => $row['code'],
                'frontend_label' => $frontendLabel,
                'is_global'      => $global,
            ];
            foreach ($columns as $column => $def) {
                if (!$def['only_init']) {
                    $data[$column] = $row[$column];
                }
            }

            $defaultValues = [];
            if ($row['_is_new'] == 1) {
                $defaultValues = [
                    'backend_table'                 => null,
                    'frontend_class'                => null,
                    'is_required'                   => 0,
                    'is_user_defined'               => 1,
                    'default_value'                 => null,
                    'note'                          => null,
                    'is_visible'                    => 1,
                    'is_system'                     => 1,
                    'input_filter'                  => null,
                    'multiline_count'               => 0,
                    'validate_rules'                => null,
                    'data_model'                    => null,
                    'sort_order'                    => 0,
                    'frontend_input_renderer'       => null,
                    'is_wysiwyg_enabled'            => 0,
                    'is_html_allowed_on_front'      => 0,
                    'used_in_product_listing'       => 0,
                    'apply_to'                      => null,
                    'position'                      => 0,
                ];

                foreach (array_keys($columns) as $column) {
                    $data[$column] = $row[$column];
                }
            }
            $data = array_merge($defaultValues, $data);
            $this->eavSetup->updateAttribute(
                $this->getEntityTypeId(),
                $row['_entity_id'],
                $data,
                null
            );

            /* Add Attribute to group and family */
            if ($row['_attribute_set_id']) {
                $this->eavSetup->addAttributeGroup(
                    $this->getEntityTypeId(),
                    $row['_attribute_set_id'],
                    self::DEFAULT_ATTRIBUTE_SET_NAME
                );
                $this->eavSetup->addAttributeToSet(
                    $this->getEntityTypeId(),
                    $row['_attribute_set_id'],
                    self::DEFAULT_ATTRIBUTE_SET_NAME,
                    $row['_entity_id']
                );
            }

            /* Add store labels */
            $stores = $this->storeHelper->getStores('lang');

            foreach ($stores as $lang => $data) {
                if (isset($row['labels-' . $lang])) {
                    foreach ($data as $store) {
                        $exists = $connection->fetchOne(
                            $connection->select()->from($this->entitiesHelper->getTable('eav_attribute_label'))
                            ->where(
                                'attribute_id = ?',
                                $row['_entity_id']
                            )->where('store_id = ?', $store['store_id'])
                        );

                        if ($exists) {
                            $values = [
                                'value' => $row['labels-' . $lang],
                            ];
                            $where = [
                                'attribute_id = ?' => $row['_entity_id'],
                                'store_id = ?'     => $store['store_id'],
                            ];
                            $connection->update(
                                $this->entitiesHelper->getTable('eav_attribute_label'),
                                $values,
                                $where
                            );
                        } else {
                            $values = [
                                'attribute_id' => $row['_entity_id'],
                                'store_id'     => $store['store_id'],
                                'value'        => $row['labels-' . $lang],
                            ];
                            $connection->insert($this->entitiesHelper->getTable('eav_attribute_label'), $values);
                        }
                    }
                }
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
     * Return type id for custom entity.
     *
     * @throws LocalizedException
     */
    protected function getEntityTypeId(): ?string
    {
        return $this->eavConfig->getEntityType(CustomEntityInterface::ENTITY)->getEntityTypeId();
    }
}
