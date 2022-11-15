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

namespace Smile\CustomEntityAkeneo\Plugin\Connector\Job;

use Akeneo\Connector\Helper\Config;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Job\Product;
use Smile\CustomEntityProductLink\Model\ResourceModel\CustomEntityProductLinkManagement;

/**
 * Product job plugin.
 *
 * @package Smile\CustomEntityAkeneo\Plugin\Connector\Job
 */
class ProductPlugin
{
    /**
     * Entities helper.
     *
     * @var Entities
     */
    protected Entities $entitiesHelper;

    /**
     * Custom entity product link management.
     *
     * @var CustomEntityProductLinkManagement
     */
    protected CustomEntityProductLinkManagement $customEntityProductLinkManagement;

    /**
     * Akeneo config helper.
     *
     * @var Config $configHelper
     */
    protected Config $configHelper;

    /**
     * Constructor.
     *
     * @param Entities $entitiesHelper
     * @param CustomEntityProductLinkManagement $customEntityProductLinkManagement
     * @param Config $configHelper
     */
    public function __construct(
        Entities $entitiesHelper,
        CustomEntityProductLinkManagement $customEntityProductLinkManagement,
        Config $configHelper
    ) {
        $this->entitiesHelper = $entitiesHelper;
        $this->customEntityProductLinkManagement = $customEntityProductLinkManagement;
        $this->configHelper = $configHelper;
    }

    /**
     * Set custom entities product links.
     *
     * @param Product $productJob
     *
     * @return void
     */
    public function afterSetValues(Product $productJob): void
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            $connection = $this->entitiesHelper->getConnection();
            $productTmpTable = $this->entitiesHelper->getTableName('product');
            $eavAttributeTable = $this->entitiesHelper->getTable('eav_attribute');
            $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');

            $selectAttributes = $connection->select()
                ->from(['eav' => $eavAttributeTable], ['attribute_code', 'attribute_id'])
                ->where('eav.frontend_input = "smile_custom_entity"');

            $customEntityAttributes = $connection->fetchAssoc($selectAttributes);

            $columns = array_keys($connection->describeTable($productTmpTable));
            foreach ($columns as $column) {
                if (!key_exists($column, $customEntityAttributes)
                    || !$connection->tableColumnExists($productTmpTable, $column)
                ) {
                    continue;
                }
                try {
                    $select = $connection->select()->from($productTmpTable, ['_entity_id', $column]);
                    $products = $connection->fetchAssoc($select);
                    foreach ($products as $id => $productData) {
                        $values = explode(",", $productData[$column]);
                        $valueIdSelect = $connection->select()
                            ->from($entityTable, ['entity_id'])
                            ->where('code IN (?)', $values)
                            ->where('import = "smile_custom_entity_record"');
                        $valueIds = $connection->fetchCol($valueIdSelect);

                        $this->customEntityProductLinkManagement->saveLinks(
                            $id,
                            $customEntityAttributes[$column]['attribute_id'],
                            $valueIds
                        );
                    }
                } catch (\Exception $e) {
                    $productJob->getLogger()->error($e->getMessage());
                }
            }
        }
    }
}
