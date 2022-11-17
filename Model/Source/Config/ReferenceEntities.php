<?php

declare(strict_types = 1);

namespace Smile\CustomEntityAkeneo\Model\Source\Config;

use Akeneo\Connector\Helper\Authenticator;
use Magento\Framework\Data\OptionSourceInterface;
use Psr\Log\LoggerInterface as Logger;

/**
 * Source of option values for reference entities.
 */
class ReferenceEntities implements OptionSourceInterface
{
    /**
     * Authenticator.
     *
     * @var Authenticator $akeneoAuthenticator
     */
    protected Authenticator $akeneoAuthenticator;

    /**
     * Logger.
     *
     * @var Logger $logger
     */
    protected Logger $logger;

    /**
     * Constructor.
     *
     * @param Authenticator $akeneoAuthenticator
     * @param Logger $logger
     */
    public function __construct(
        Authenticator $akeneoAuthenticator,
        Logger $logger
    ) {
        $this->akeneoAuthenticator = $akeneoAuthenticator;
        $this->logger = $logger;
    }

    /**
     * Load reference entities from api.
     *
     * @return array
     */
    public function getReferenceEntities(): array
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

    /**
     * Return array of options as value-label pairs.
     *
     * @return array
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
}
