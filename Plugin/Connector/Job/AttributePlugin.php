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
    public function __construct(
        protected Entities $entitiesHelper,
        protected EavSetup $eavSetup,
        protected Config   $configHelper
    ) {
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
            $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
            $select = $connection->select()
                ->from(
                    ['tmp' => $this->entitiesHelper->getTableName('attribute')],
                    [
                        '_entity_id',
                        'reference_data_name',
                        'frontend_input',
                    ]
                )->joinLeft(
                    ['eav' => $this->entitiesHelper->getTable('eav_attribute')],
                    'tmp._entity_id = eav.attribute_id',
                    [
                        'magento_frontend_input' => 'frontend_input',
                    ]
                )->where("tmp.frontend_input = 'smile_custom_entity'");
            $attributes = $connection->fetchAll($select);
            $productEntityTypeId = $this->eavSetup->getEntityTypeId(ProductAttributeInterface::ENTITY_TYPE_CODE);
            foreach ($attributes as $attribute) {
                if (
                    !$attribute['reference_data_name'] || !$attribute['magento_frontend_input']
                    || $attribute['frontend_input'] !== $attribute['magento_frontend_input']
                ) {
                    continue;
                }
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
                    $values
                );
            }
        }
    }
}
