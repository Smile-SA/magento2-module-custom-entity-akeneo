<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Plugin\Connector\Job;

use Akeneo\Connector\Helper\Config;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Job\Product;
use Exception;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Zend_Db_Expr as Expr;

/**
 * Product job plugin.
 */
class ProductPlugin
{
    public function __construct(
        protected Entities $entitiesHelper,
        protected Config $configHelper
    ) {
    }

    /**
     * Plugin on afterUpdateOption method to manage refecerence entities.
     */
    public function afterUpdateOption(Product $productJob): void
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            $connection = $this->entitiesHelper->getConnection();
            $productTmpTable = $this->entitiesHelper->getTableName('product');
            $eavAttributeTable = $this->entitiesHelper->getTable('eav_attribute');
            $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');

            $selectAttributes = $connection->select()
                ->from(['eav' => $eavAttributeTable], ['attribute_code', 'attribute_id'])
                ->joinLeft(
                    ['ace' => $entityTable],
                    'eav.custom_entity_attribute_set_id = ace.entity_id',
                    ['code']
                )->where(
                    'ace.import = "smile_custom_entity"'
                )->where('eav.frontend_input = "smile_custom_entity"');

            $customEntityAttributes = $connection->fetchAssoc($selectAttributes);

            $columns = array_keys($connection->describeTable($productTmpTable));
            foreach ($columns as $column) {
                if (
                    !key_exists($column, $customEntityAttributes)
                    || !$connection->tableColumnExists($productTmpTable, $column)
                ) {
                    continue;
                }
                try {
                    $prefixLength = strlen($customEntityAttributes[$column]['code']) + 2;

                    // Sub select to increase performance versus FIND_IN_SET
                    $subSelect = $connection->select()
                        ->from(
                            ['c' => $entityTable],
                            [
                                'code' => sprintf('SUBSTRING(`c`.`code`, %s)', $prefixLength),
                                'entity_id' => 'c.entity_id',
                            ]
                        )
                        ->where(sprintf('c.code LIKE "%s%s"', $customEntityAttributes[$column]['code'], '%'))
                        ->where('c.import = ?', 'smile_custom_entity_record');

                    // if no option no need to continue process
                    if (!$connection->query($subSelect)->rowCount()) {
                        continue;
                    }

                    //in case of multiselect
                    $conditionJoin = "IF ( locate(',', `" . $column . "`) > 0 , "
                        . new Expr("FIND_IN_SET(`c1`.`code`,`p`.`" . $column . "`) > 0")
                        . ", `p`.`" . $column . "` = `c1`.`code` )";

                    $select = $connection->select()->from(
                        ['p' => $productTmpTable],
                        ['identifier' => 'p.identifier', 'entity_id' => 'p._entity_id']
                    )->joinInner(
                        ['c1' => new Expr('(' . (string) $subSelect . ')')],
                        new Expr($conditionJoin),
                        [$column => new Expr('GROUP_CONCAT(`c1`.`entity_id` SEPARATOR ",")')]
                    )->group('p.identifier');

                    $query = $connection->insertFromSelect(
                        $select,
                        $productTmpTable,
                        ['identifier', '_entity_id', $column],
                        AdapterInterface::INSERT_ON_DUPLICATE
                    );

                    $connection->query($query);
                } catch (Exception $e) {
                    $productJob->getLogger()->error($e->getMessage());
                }
            }
        }
    }
}
