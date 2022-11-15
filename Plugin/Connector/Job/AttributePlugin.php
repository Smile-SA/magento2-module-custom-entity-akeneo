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
use Akeneo\Connector\Job\Attribute;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Exception\LocalizedException;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;

/**
 * Attribute job plugin.
 *
 * @package Smile\CustomEntityAkeneo\Plugin\Connector\Job
 */
class AttributePlugin
{
    /**
     * Entities helper.
     *
     * @var Entities
     */
    protected Entities $entitiesHelper;

    /**
     * Eav setup.
     *
     * @var EavSetup
     */
    protected EavSetup $eavSetup;

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
     * @param EavSetup $eavSetup
     * @param Config $configHelper
     */
    public function __construct(
        Entities $entitiesHelper,
        EavSetup $eavSetup,
        Config $configHelper
    ) {
        $this->entitiesHelper = $entitiesHelper;
        $this->eavSetup = $eavSetup;
        $this->configHelper = $configHelper;
    }

    /**
     * Add type to smile_custom_entity product attributes.
     *
     * @param Attribute $attributeJob
     *
     * @return void
     *
     * @throws LocalizedException
     */
    public function afterAddAttributes(Attribute $attributeJob): void
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            $connection = $this->entitiesHelper->getConnection();
            $tmpTable = $this->entitiesHelper->getTableName('attribute');
            $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
            $select = $connection->select()->from($tmpTable)->where("frontend_input = 'smile_custom_entity'");
            $attributes = $connection->fetchAll($select);
            $productEntityTypeId = $this->eavSetup->getEntityTypeId(ProductAttributeInterface::ENTITY_TYPE_CODE);
            foreach ($attributes as $attribute) {
                if ($attribute['reference_data_name']) {
                    $entitySelect = $connection->select()->from($entityTable, ['entity_id'])
                        ->where('code = ?', $attribute['reference_data_name'])
                        ->where('import = "smile_custom_entity"');
                    $customEntityTypeId = $connection->fetchOne($entitySelect);
                    if (!$customEntityTypeId) {
                        $customEntityTypeId = $this->eavSetup->getDefaultAttributeSetId(
                            CustomEntityInterface::ENTITY
                        );
                    }
                    $this->eavSetup->updateAttribute(
                        $productEntityTypeId,
                        $attribute['_entity_id'],
                        'custom_entity_attribute_set_id',
                        $customEntityTypeId
                    );
                }
            }
        }
    }
}
