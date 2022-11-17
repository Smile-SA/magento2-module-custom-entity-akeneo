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

namespace Smile\CustomEntityAkeneo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Config for reference entities import.
 *
 * @category  Class
 * @package   Smile\CustomEntityAkeneo\Model
 */
class ConfigManager
{
    /**
     * #@+
     * Config patch.
     */
    const FILTER_MODE = 'akeneo_connector/smile_custom_entity/mode';
    const FILTER_ENTITIES = 'akeneo_connector/smile_custom_entity/reference_entities';
    const ENTITY_STATUS = 'akeneo_connector/smile_custom_entity/status';
    const CACHE_TYPE_CUSTOM_ENTITY = 'akeneo_connector/cache/smile_custom_entity';
    const CACHE_TYPE_CUSTOM_ENTITY_ATTRIBUTE = 'akeneo_connector/cache/smile_custom_entity_attribute';
    const CACHE_TYPE_CUSTOM_ENTITY_RECORD = 'akeneo_connector/cache/smile_custom_entity_record';
    /**#@-*/

    /**
     * Scope config.
     *
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;

    /**
     * Constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Return entities filter mode.
     *
     * @return string|null
     */
    public function getFilterMode(): ?string
    {
        return $this->scopeConfig->getValue(self::FILTER_MODE);
    }

    /**
     * Return selected entities.
     *
     * @return array
     */
    public function getFilterEntities(): array
    {
        $configuration = $this->scopeConfig->getValue(self::FILTER_ENTITIES);
        return explode(',', $configuration ?? '');
    }

    /**
     * Return default status for entity record.
     *
     * @return int
     */
    public function getDefaultEntityStatus(): int
    {
        return (int) $this->scopeConfig->getValue(self::ENTITY_STATUS);
    }
    /**
     * Get cache type for custom entity.
     *
     * @return array
     */
    public function getCacheTypeEntity(): array
    {
        $configuration = $this->scopeConfig->getValue(self::CACHE_TYPE_CUSTOM_ENTITY);
        return $configuration ? explode(',', $configuration) : [];
    }

    /**
     * Get cache type for custom entity attribute.
     *
     * @return array
     */
    public function getCacheTypeAttribute(): array
    {
        $configuration = $this->scopeConfig->getValue(self::CACHE_TYPE_CUSTOM_ENTITY_ATTRIBUTE);
        return $configuration ? explode(',', $configuration) : [];
    }

    /**
     * Get cache type for custom entity record.
     *
     * @return array
     */
    public function getCacheTypeRecord(): array
    {
        $configuration = $this->scopeConfig->getValue(self::CACHE_TYPE_CUSTOM_ENTITY_RECORD);
        return $configuration ? explode(',', $configuration) : [];
    }
}
