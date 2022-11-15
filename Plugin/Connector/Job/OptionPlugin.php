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
use Akeneo\Connector\Job\Option;

/**
 * Option job plugin.
 *
 * @package Smile\CustomEntityAkeneo\Plugin\Connector\Job
 */
class OptionPlugin
{
    /**
     * Entities helper.
     *
     * @var Entities
     */
    protected Entities $entitiesHelper;

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
     * @param Config $configHelper
     */
    public function __construct(
        Entities $entitiesHelper,
        Config $configHelper
    ) {
        $this->entitiesHelper = $entitiesHelper;
        $this->configHelper = $configHelper;
    }

    /**
     * Remove reference entity attribute options.
     *
     * @param Option $optionJob
     *
     * @return void
     */
    public function afterInsertData(Option $optionJob): void
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            $connection = $this->entitiesHelper->getConnection();
            $tmpOptionTable = $this->entitiesHelper->getTableName('option');
            $eavAttributeTable = $this->entitiesHelper->getTable('eav_attribute');
            $select = $connection->select()
                ->from(['o' => $tmpOptionTable], ['attribute'])
                ->joinLeft(['eav' => $eavAttributeTable], 'o.attribute = eav.attribute_code', [])
                ->where('eav.frontend_input = "smile_custom_entity"')
                ->group('o.attribute');
            $referenceEntityAttributes = $connection->fetchCol($select);
            $connection->delete(
                $tmpOptionTable,
                [
                    'attribute IN (?)' => $referenceEntityAttributes
                ]
            );
        }
    }
}
