<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Model\Source\Config;

use Akeneo\Connector\Helper\Authenticator;
use Magento\Framework\Data\OptionSourceInterface;
use Psr\Log\LoggerInterface as Logger;

/**
 * Source of option values for reference entities.
 */
class ReferenceEntities implements OptionSourceInterface
{
    public function __construct(
        protected Authenticator $akeneoAuthenticator,
        protected Logger $logger
    ) {
    }

    /**
     * Return array of options as value-label pairs.
     */
    public function toOptionArray(): array
    {
        $entities = $this->getReferenceEntities();
        $optionArray = [];

        foreach ($entities as $optionValue => $optionLabel) {
            $optionArray[] = [
                'value' => $optionValue,
                'label' => $optionLabel,
            ];
        }

        return $optionArray;
    }

    /**
     * Load reference entities from api.
     */
    protected function getReferenceEntities(): array
    {
        $entities = [];

        try {
            $client = $this->akeneoAuthenticator->getAkeneoApiClient();

            if (empty($client)) {
                return $entities;
            }

            $akeneoReferenceEntities = $client->getReferenceEntityApi()->all();

            foreach ($akeneoReferenceEntities as $referenceEntity) {
                if (!isset($referenceEntity['code'])) {
                    continue;
                }
                $entities[$referenceEntity['code']] = $referenceEntity['code'];
            }
        } catch (\Exception $exception) {
            $this->logger->warning($exception->getMessage());
        }

        return $entities;
    }
}
