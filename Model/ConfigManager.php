<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Config for reference entities import.
 */
class ConfigManager
{
    /**
     * Config patch.
     */
    public const FILTER_MODE = 'akeneo_connector/smile_custom_entity/mode';
    public const FILTER_ENTITIES = 'akeneo_connector/smile_custom_entity/reference_entities';
    public const ENTITY_STATUS = 'akeneo_connector/smile_custom_entity/status';
    public const CACHE_TYPE_CUSTOM_ENTITY = 'akeneo_connector/cache/smile_custom_entity';
    public const CACHE_TYPE_CUSTOM_ENTITY_ATTRIBUTE = 'akeneo_connector/cache/smile_custom_entity_attribute';
    public const CACHE_TYPE_CUSTOM_ENTITY_RECORD = 'akeneo_connector/cache/smile_custom_entity_record';

    public function __construct(protected ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Return entities filter mode.
     */
    public function getFilterMode(): ?string
    {
        return $this->scopeConfig->getValue(self::FILTER_MODE);
    }

    /**
     * Return selected entities.
     */
    public function getFilterEntities(): array
    {
        $configuration = $this->scopeConfig->getValue(self::FILTER_ENTITIES);
        return explode(',', $configuration ?? '');
    }

    /**
     * Return default status for entity record.
     */
    public function getDefaultEntityStatus(): int
    {
        return (int) $this->scopeConfig->getValue(self::ENTITY_STATUS);
    }
    /**
     * Get cache type for custom entity.
     */
    public function getCacheTypeEntity(): array
    {
        $configuration = $this->scopeConfig->getValue(self::CACHE_TYPE_CUSTOM_ENTITY);
        return $configuration ? explode(',', $configuration) : [];
    }

    /**
     * Get cache type for custom entity attribute.
     */
    public function getCacheTypeAttribute(): array
    {
        $configuration = $this->scopeConfig->getValue(self::CACHE_TYPE_CUSTOM_ENTITY_ATTRIBUTE);
        return $configuration ? explode(',', $configuration) : [];
    }

    /**
     * Get cache type for custom entity record.
     */
    public function getCacheTypeRecord(): array
    {
        $configuration = $this->scopeConfig->getValue(self::CACHE_TYPE_CUSTOM_ENTITY_RECORD);
        return $configuration ? explode(',', $configuration) : [];
    }
}
