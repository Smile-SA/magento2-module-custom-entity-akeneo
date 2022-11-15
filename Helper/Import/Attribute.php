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

namespace Smile\CustomEntityAkeneo\Helper\Import;

use Akeneo\Connector\Helper\Import\Attribute as AkeneoAttributeHelper;

/**
 * Attribute import helper.
 *
 * @category  Class
 * @package   Smile\CustomEntityAkeneo\Helper\Import
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
