<?php

declare(strict_types = 1);

namespace Smile\CustomEntityAkeneo\Helper\Import;

use Akeneo\Connector\Helper\Import\Attribute as AkeneoAttributeHelper;

/**
 * Attribute import helper.
 *
 * @category  Class
 */
class Attribute extends AkeneoAttributeHelper
{
    /**
     * Match Pim type with Magento attribute logic.
     *
     * @param string $pimType
     *
     * @return array
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
            'reference_entity_single_link' => 'select',
            'reference_entity_multiple_links' => 'multiselect'
        ];

        return isset($types[$pimType]) ? $this->getConfiguration($types[$pimType]) : $this->getConfiguration();
    }
}
