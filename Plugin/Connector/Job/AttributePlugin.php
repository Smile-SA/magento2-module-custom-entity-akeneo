<?php

declare(strict_types=1);

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
 */
class AttributePlugin
{
    /**
     * Entities helper.
     */
    protected Entities $entitiesHelper;

    /**
     * Eav setup.
     */
    protected EavSetup $eavSetup;

    /**
     * Akeneo config helper.
     */
    protected Config $configHelper;

    /**
     * Constructor.
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
     * @throws LocalizedException
     */
    public function afterAddAttributes(Attribute $attributeJob): void
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            $connection = $this->entitiesHelper->getConnection();
            $tmpTable = $this->entitiesHelper->getTableName('attribute');
            $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
            $select = $connection->select()->from($tmpTable)
                ->where("frontend_input = 'smile_custom_entity'")
                ->where('_is_new == 1');
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
                    $values = [
                        'is_html_allowed_on_front' => 1,
                        'custom_entity_attribute_set_id' => $customEntityTypeId,
                    ];
                    $this->eavSetup->updateAttribute(
                        $productEntityTypeId,
                        $attribute['_entity_id'],
                        $values,
                        null
                    );
                }
            }
        }
    }
}
