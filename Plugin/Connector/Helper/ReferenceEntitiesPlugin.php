<?php

declare(strict_types = 1);

namespace Smile\CustomEntityAkeneo\Plugin\Connector\Helper;

use Akeneo\Connector\Helper\Config;
use Akeneo\Connector\Helper\ReferenceEntities;

/**
 * ReferenceEntities helper plugin.
 */
class ReferenceEntitiesPlugin
{
    /**
     * Akeneo config helper.
     *
     * @var Config $configHelper
     */
    protected Config $configHelper;

    /**
     * Constructor.
     *
     * @param Config $configHelper
     */
    public function __construct(
        Config $configHelper
    ) {
        $this->configHelper = $configHelper;
    }

    /**
     * Change reference entity frontend input type to smile_custom_entity.
     *
     * @param ReferenceEntities $subject
     * @param $result
     *
     * @return array
     */
    public function afterGetMappingReferenceEntities(ReferenceEntities $subject,array $result): array
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            $result = array_merge(
                $result,
                [
                    'akeneo_reference_entity_collection' => 'smile_custom_entity',
                    'akeneo_reference_entity'            => 'smile_custom_entity'
                ]
            );
        }
        return $result;
    }
}
