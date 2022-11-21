<?php

declare(strict_types = 1);

namespace Smile\CustomEntityAkeneo\Helper\Import;

use Akeneo\Connector\Helper\Config as ConfigHelper;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Helper\Store as StoreHelper;
use Magento\Catalog\Model\Product as BaseProductModel;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Zend_Db_Expr as Expr;
use Zend_Db_Statement_Exception;

/**
 * Options import helper.
 */
class Option extends Entities
{
    /**
     * Store helper.
     *
     * @var StoreHelper
     */
    protected StoreHelper $storeHelper;

    /**
     * Constructor.
     *
     * @param ResourceConnection $connection
     * @param DeploymentConfig $deploymentConfig
     * @param BaseProductModel $product
     * @param ConfigHelper $configHelper
     * @param LoggerInterface $logger
     * @param StoreHelper $storeHelper
     */
    public function __construct(
        ResourceConnection $connection,
        DeploymentConfig $deploymentConfig,
        BaseProductModel $product,
        ConfigHelper $configHelper,
        LoggerInterface $logger,
        StoreHelper $storeHelper
    ) {
        parent::__construct(
            $connection,
            $deploymentConfig,
            $product,
            $configHelper,
            $logger
        );
        $this->storeHelper = $storeHelper;
    }

    /**
     * Match Magento ID with code.
     *
     * @param string $pimKey
     * @param string $entityTable
     * @param string $entityKey
     * @param string $import
     * @param string|null $prefix
     *
     * @return $this
     *
     * @throws LocalizedException
     * @throws Zend_Db_Statement_Exception
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function matchEntity(
        string $pimKey,
        string $entityTable,
        string $entityKey,
        string $import,
        string $prefix = null
    ): self {
        $localeCode = $this->storeHelper->getAdminLang();
        $connection = $this->connection;
        $tableName = $this->getTableName($import);

        $connection->delete($tableName, [$pimKey . ' = ?' => '']);
        $akeneoConnectorTable = $this->getTable('akeneo_connector_entities');
        $entityTable = $this->getTable($entityTable);
        if ($entityKey == 'entity_id') {
            $entityKey = $this->getColumnIdentifier($entityTable);
        }

        /* Connect existing Magento options to new Akeneo items */
        $select = $connection->select()->from($akeneoConnectorTable, ['entity_id' => 'entity_id'])->where(
            'import = ?',
            $import
        );

        $existingEntities = $connection->query($select)->fetchAll();
        $existingEntities = array_column($existingEntities, 'entity_id');
        $entityTypeId = $this->configHelper->getEntityTypeId(CustomEntityInterface::ENTITY);

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
                'import'    => $import,
                'code'      => $currentEntity['attribute'] . '-' . $currentEntity['code'],
                'entity_id' => $entityOptionId,
            ];
            $connection->insertOnDuplicate($akeneoConnectorTable, $values);
        }

        $sql = '
            UPDATE `' . $tableName . '` t
            SET `_entity_id` = (
                SELECT `entity_id` FROM `' . $akeneoConnectorTable . '` c
                WHERE ' . ($prefix
                    ? 'CONCAT(t.`' . $prefix . '`, "-", t.`' . $pimKey . '`)'
                    : 't.`' . $pimKey . '`') . ' = c.`code`
                AND c.`import` = "' . $import . '"
            )
        ';
        $connection->query(
            $sql
        );

        /* Set entity_id for new entities */
        $query = $connection->query('SHOW TABLE STATUS LIKE "' . $entityTable . '"');
        $row = $query->fetch();

        $connection->query('SET @id = ' . (int)$row['Auto_increment']);
        $values = [
            '_entity_id' => new Expr('@id := @id + 1'),
            '_is_new'    => new Expr('1'),
        ];
        $connection->update($tableName, $values, '_entity_id IS NULL');

        /* Update akeneo_connector_entities table with code and new entity_id */
        $select = $connection->select()->from(
            $tableName,
            [
                'import'    => new Expr("'" . $import . "'"),
                'code'      => $prefix ? new Expr('CONCAT(`' . $prefix . '`, "-", `' . $pimKey . '`)') : $pimKey,
                'entity_id' => '_entity_id',
            ]
        )->where('_is_new = ?', 1);

        $connection->query(
            $connection->insertFromSelect($select, $akeneoConnectorTable, ['import', 'code', 'entity_id'], 2)
        );

        /* Update entity table auto increment */
        $count = $connection->fetchOne(
            $connection->select()->from($tableName, [new Expr('COUNT(*)')])->where('_is_new = ?', 1)
        );
        if ($count) {
            $maxCode = $connection->fetchOne(
                $connection->select()->from($akeneoConnectorTable, new Expr('MAX(`entity_id`)'))->where(
                    'import = ?',
                    $import
                )
            );
            $maxEntity = $connection->fetchOne(
                $connection->select()->from($entityTable, new Expr('MAX(`' . $entityKey . '`)'))
            );
            $connection->query(
                'ALTER TABLE `' . $entityTable . '` AUTO_INCREMENT = ' . (max((int)$maxCode, (int)$maxEntity) + 1)
            );
        }

        return $this;
    }
}
