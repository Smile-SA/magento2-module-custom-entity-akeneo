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
use Smile\CustomEntityAkeneo\Helper\Import\Option as OptionHelper;
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
        protected OptionHelper $optionHelper,
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
                if ($attribute['type'] == 'single_option' || $attribute['type'] == 'multiple_options') {
                    $optionsApiResult = $attributeOptionApi->all((string) $entityCode, (string) $attribute['code']);
                    foreach ($optionsApiResult as $option) {
                        $option['attribute'] = $entityCode . '_' . $attribute['code'];
                        $this->entitiesHelper->insertDataFromApi(
                            $option,
                            $this->jobExecutor->getCurrentJob()->getCode()
                        );
                        $optionsCount++;
                    }
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
     *
     * @throws Zend_Db_Statement_Exception
     * @throws LocalizedException
     */
    public function matchEntities(): void
    {
        $this->optionHelper->matchEntity(
            'code',
            'eav_attribute_option',
            'option_id',
            $this->jobExecutor->getCurrentJob()->getCode(),
            'attribute'
        );
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
}
