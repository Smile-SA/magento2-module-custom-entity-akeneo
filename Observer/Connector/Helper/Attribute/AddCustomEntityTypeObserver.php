<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Observer\Connector\Helper\Attribute;

use Akeneo\Connector\Helper\Config;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smile\CustomEntityProductLink\Model\Entity\Attribute\Frontend\CustomEntity;

/**
 * Attribute import job observer.
 */
class AddCustomEntityTypeObserver implements ObserverInterface
{
    /**
     * Akeneo config helper.
     */
    protected Config $configHelper;

    /**
     * Constructor.
     */
    public function __construct(
        Config $configHelper
    ) {
        $this->configHelper = $configHelper;
    }

    /**
     * Add smile_custom_entity type to attribute configuration.
     */
    public function execute(Observer $observer): void
    {
        if (!$this->configHelper->isReferenceEntitiesEnabled()) {
            /** @var DataObject $response */
            $response = $observer->getData('response');
            $types = $response->getTypes() && is_array($response->getTypes()) ? $response->getTypes() : [];
            $additionalTypes = array_merge(
                $types,
                [
                    'smile_custom_entity' => [
                        'backend_type'   => 'static',
                        'frontend_input' => 'smile_custom_entity',
                        'backend_model'  => null,
                        'source_model'   => null,
                        'frontend_model' => CustomEntity::class,
                    ],
                ]
            );
            $response->setTypes($additionalTypes);
        }
    }
}
