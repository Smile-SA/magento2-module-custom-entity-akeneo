<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Helper\Import;

use Akeneo\Connector\Helper\Import\Attribute as AkeneoAttributeHelper;
use Magento\Catalog\Model\Product\Attribute\Backend\Price;
use Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend;
use Magento\Eav\Model\Entity\Attribute\Backend\Datetime;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\DataObject;
use Smile\CustomEntity\Model\Source\Attribute\CustomEntity;

/**
 * Attribute import helper.
 */
class Attribute extends AkeneoAttributeHelper
{
    /**
     * @inheritdoc
     */
    public function getType($pimType = 'default'): array
    {
        $types = [
            'default' => 'text',
            'text' => 'text',
            'image' => 'image',
            'number' => 'text',
            'single_option' => 'select',
            'multiple_options' => 'multiselect',
            'reference_entity_single_link' => 'smile_custom_entity_select',
            'reference_entity_multiple_links' => 'smile_custom_entity_select',
        ];

        return isset($types[$pimType]) ? $this->getConfiguration($types[$pimType]) : $this->getConfiguration();
    }

    /**
     * @inheritdoc
     */
    protected function getConfiguration($inputType = 'default'): array
    {
        $types = [
            'default'     => [
                'backend_type'   => 'varchar',
                'frontend_input' => 'text',
                'backend_model'  => null,
                'source_model'   => null,
                'frontend_model' => null,
            ],
            'text'        => [
                'backend_type'   => 'varchar',
                'frontend_input' => 'text',
                'backend_model'  => null,
                'source_model'   => null,
                'frontend_model' => null,
            ],
            'textarea'    => [
                'backend_type'   => 'text',
                'frontend_input' => 'textarea',
                'backend_model'  => null,
                'source_model'   => null,
                'frontend_model' => null,
            ],
            'date'        => [
                'backend_type'   => 'datetime',
                'frontend_input' => 'date',
                'backend_model'  => Datetime::class,
                'source_model'   => null,
                'frontend_model' => null,
            ],
            'boolean'     => [
                'backend_type'   => 'int',
                'frontend_input' => 'boolean',
                'backend_model'  => null,
                'source_model'   => Boolean::class,
                'frontend_model' => null,
            ],
            'multiselect' => [
                'backend_type'   => 'varchar',
                'frontend_input' => 'multiselect',
                'backend_model'  => ArrayBackend::class,
                'source_model'   => null,
                'frontend_model' => null,
            ],
            'select'      => [
                'backend_type'   => 'int',
                'frontend_input' => 'select',
                'backend_model'  => null,
                'source_model'   => Table::class,
                'frontend_model' => null,
            ],
            'price'       => [
                'backend_type'   => 'decimal',
                'frontend_input' => 'price',
                'backend_model'  => Price::class,
                'source_model'   => null,
                'frontend_model' => null,
            ],
            'smile_custom_entity_select' => [
                'backend_type'   => 'varchar',
                'frontend_input' => 'smile_custom_entity_select',
                'backend_model'  => ArrayBackend::class,
                'source_model'   => CustomEntity::class,
                'frontend_model' => null,
            ],
        ];

        $response = new DataObject();
        $response->setData('types', $types);

        $this->eventManager->dispatch(
            'akeneo_connector_attribute_get_configuration_add_before',
            ['response' => $response]
        );

        $types = $response->getData('types');

        return $types[$inputType] ?? $types['default'];
    }
}
