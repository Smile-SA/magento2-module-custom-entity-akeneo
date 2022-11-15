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

namespace Smile\CustomEntityAkeneo\Helper\Import;

use Akeneo\Connector\Helper\Config as ConfigHelper;
use Akeneo\Connector\Helper\Import\Entities;
use Magento\Catalog\Model\Product as BaseProductModel;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Smile\CustomEntityAkeneo\Model\ConfigManager;
use Smile\CustomEntityAkeneo\Model\Source\Config\Mode;
use Zend_Db_Statement_Exception;

/**
 * Reference Entity import helper.
 *
 * @category  Class
 * @package   Smile\CustomEntityAkeneo\Helper\Import
 */
class ReferenceEntity extends Entities
{
    /**
     * Config.
     *
     * @var ConfigManager
     */
    protected ConfigManager $configManager;

    /**
     * Constructor.
     *
     * @param ResourceConnection $connection
     * @param DeploymentConfig $deploymentConfig
     * @param BaseProductModel $product
     * @param ConfigHelper $configHelper
     * @param LoggerInterface $logger
     * @param ConfigManager $configManager
     */
    public function __construct(
        ResourceConnection $connection,
        DeploymentConfig $deploymentConfig,
        BaseProductModel $product,
        ConfigHelper $configHelper,
        LoggerInterface $logger,
        ConfigManager $configManager
    ) {
        parent::__construct(
            $connection,
            $deploymentConfig,
            $product,
            $configHelper,
            $logger
        );
        $this->configManager = $configManager;
    }

    /**
     * Return reference entities to import depends on configuration and imported entities.
     *
     * @return array
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function getEntitiesToImport(): array
    {
        $connection = $this->getConnection();
        $entityTable = $this->getTable('akeneo_connector_entities');
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
     *
     * @param string $code
     * @param int    $entityTypeId
     *
     * @return bool|array
     */
    public function getAttribute($code, $entityTypeId): bool|array
    {
        $connection = $this->connection;

        $attribute = $connection->fetchRow(
            $connection->select()->from(
                $this->getTable('eav_attribute'),
                [
                    AttributeInterface::ATTRIBUTE_ID,
                    AttributeInterface::BACKEND_TYPE,
                    AttributeInterface::FRONTEND_INPUT
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
