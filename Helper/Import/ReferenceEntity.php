<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Helper\Import;

use Akeneo\Connector\Helper\Import\Entities;
use Magento\Eav\Api\Data\AttributeInterface;
use Smile\CustomEntityAkeneo\Model\ConfigManager;
use Smile\CustomEntityAkeneo\Model\Source\Config\Mode;
use Zend_Db_Statement_Exception;

/**
 * Reference Entity import helper.
 */
class ReferenceEntity
{
    public function __construct(
        protected Entities $entitiesHelper,
        protected ConfigManager $configManager
    ) {
    }

    /**
     * Return reference entities to import depends on configuration and imported entities.
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function getEntitiesToImport(): array
    {
        $connection = $this->entitiesHelper->getConnection();
        $entityTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $selectExistingEntities = $connection->select()
            ->from(['e' => $entityTable], 'e.code')
            ->where('e.import = ?', 'smile_custom_entity');
        $entities = array_column($connection->query($selectExistingEntities)->fetchAll(), 'code');

        $mode = $this->configManager->getFilterMode();
        if ($mode == Mode::SPECIFIC) {
            $specificEntities = $this->configManager->getFilterEntities();
            $entities = array_intersect($entities, $specificEntities);
        }

        return $entities;
    }

    /**
     * Retrieve attribute.
     */
    public function getAttribute(string $code, int $entityTypeId): bool|array
    {
        $connection = $this->entitiesHelper->getConnection();

        $attribute = $connection->fetchRow(
            $connection->select()->from(
                $this->entitiesHelper->getTable('eav_attribute'),
                [
                    AttributeInterface::ATTRIBUTE_ID,
                    AttributeInterface::BACKEND_TYPE,
                    AttributeInterface::FRONTEND_INPUT,
                ]
            )->where(AttributeInterface::ENTITY_TYPE_ID . ' = ?', $entityTypeId)->where(
                AttributeInterface::ATTRIBUTE_CODE . ' = ?',
                $code
            )->limit(1)
        );

        if (empty($attribute)) {
            return false;
        }

        return $attribute;
    }
}
