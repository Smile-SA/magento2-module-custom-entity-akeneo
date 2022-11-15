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

namespace Smile\CustomEntityAkeneo\Model\Source\Config;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source of option values for import mode.
 *
 * @category  Class
 * @package   Smile\CustomEntityAkeneo\Model\Source\Config
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
