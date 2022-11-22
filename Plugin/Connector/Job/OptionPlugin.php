<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Plugin\Connector\Job;

use Akeneo\Connector\Helper\Config;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Job\Option;

/**
 * Option job plugin.
 */
class OptionPlugin
{
    public function __construct(protected Entities $entitiesHelper, protected Config $configHelper)
    {
    }

    /**
     * Remove reference entity attribute options.
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
                    'attribute IN (?)' => $referenceEntityAttributes,
                ]
            );
        }
    }
}
