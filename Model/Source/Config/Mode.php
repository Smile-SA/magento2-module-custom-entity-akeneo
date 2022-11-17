<?php

declare(strict_types = 1);

namespace Smile\CustomEntityAkeneo\Model\Source\Config;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source of option values for import mode.
 */
class Mode implements OptionSourceInterface
{
    /**
     * #@+
     * Mode type code.
     */
    const ALL = 'all';
    const SPECIFIC = 'specific';
    /**#@-*/

    /**
     * Return array of options as value-label pairs.
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            self::ALL => __('All'),
            self::SPECIFIC => __('Specific or Choose'),
        ];
    }
}
