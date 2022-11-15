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

namespace Smile\CustomEntityAkeneo\Job;

use Akeneo\Connector\Helper\Authenticator;
use Akeneo\Connector\Helper\Config as ConfigHelper;
use Akeneo\Connector\Helper\Import\Entities;
use Akeneo\Connector\Helper\Output as OutputHelper;
use Akeneo\Connector\Helper\Store as StoreHelper;
use Akeneo\Connector\Job\Import;
use Exception;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Magento\Eav\Model\Config as EavConfig;
use Smile\CustomEntityAkeneo\Model\ConfigManager;
use Smile\CustomEntityAkeneo\Model\Source\Config\Mode;
use Zend_Db_Exception;
use Zend_Db_Expr as Expr;
use Zend_Db_Statement_Exception;

/**
 * Custom entity type (reference entity) import job.
 *
 * @category  Class
 * @package   Smile\CustomEntityAkeneo\Job
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CustomEntity extends Import
{
    /**
     * Import code.
     *
     * @var string $code
     */
    protected string $code = 'smile_custom_entity';

    /**
     * Import name.
     *
     * @var string $name
     */
    protected string $name = 'Smile Custom Entity';

    /**
     * Store helper.
     *
     * @var StoreHelper $storeHelper
     */
    protected StoreHelper $storeHelper;

    /**
     * Eav config.
     *
     * @var EavConfig $eavConfig
     */
    protected EavConfig $eavConfig;

    /**
     * Attribute set factory.
     *
     * @var SetFactory $attributeSetFactory
     */
    protected SetFactory $attributeSetFactory;

    /**
     * Cache type list.
     *
     * @var TypeListInterface
     */
    protected TypeListInterface $cacheTypeList;

    /**
     * Custom entity config.
     *
     * @var ConfigManager
     */
    protected ConfigManager $configManager;

    /**
     * Constructor.
     *
     * @param OutputHelper $outputHelper
     * @param ManagerInterface $eventManager
     * @param Authenticator $authenticator
     * @param Entities $entitiesHelper
     * @param ConfigHelper $configHelper
     * @param StoreHelper $storeHelper
     * @param EavConfig $eavConfig
     * @param SetFactory $attributeSetFactory
     * @param TypeListInterface $cacheTypeList
     * @param ConfigManager $configManager
     * @param array $data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        OutputHelper      $outputHelper,
        ManagerInterface  $eventManager,
        Authenticator     $authenticator,
        Entities          $entitiesHelper,
        ConfigHelper      $configHelper,
        StoreHelper       $storeHelper,
        EavConfig         $eavConfig,
        SetFactory        $attributeSetFactory,
        TypeListInterface $cacheTypeList,
        ConfigManager     $configManager,
        array             $data = []
    ) {
        parent::__construct(
            $outputHelper,
            $eventManager,
            $authenticator,
            $entitiesHelper,
            $configHelper,
            $data
        );
        $this->storeHelper = $storeHelper;
        $this->eavConfig = $eavConfig;
        $this->attributeSetFactory = $attributeSetFactory;
        $this->cacheTypeList = $cacheTypeList;
        $this->configManager = $configManager;

    }

    /**
     * Create temporary table.
     *
     * @return void
     *
     * @throws Zend_Db_Exception
     */
    public function createTable(): void
    {
        $lang = $this->storeHelper->getAdminLang();
        $labelColumn = 'labels-' . $lang;
        $this->entitiesHelper->createTmpTable(
            ['code', $labelColumn],
            $this->jobExecutor->getCurrentJob()->getCode()
        );
    }

    /**
     * Load data and insert it in the temporary table.
     *
     * @return void
     *
     * @throws AlreadyExistsException
     */
    public function insertData(): void
    {
        $referenceEntities = $this->loadReferenceEntitiesFromApi();
        if (empty($referenceEntities)) {
            $this->jobExecutor->setMessage(__('No results retrieved from Akeneo'));
            $this->jobExecutor->afterRun(true);
            return;
        }
        $this->jobExecutor->setAdditionalMessage(
            __('%1 custom entity(ies) loaded', count($referenceEntities))
        );
        foreach ($referenceEntities as $entity) {
            $this->entitiesHelper->insertDataFromApi($entity, $this->jobExecutor->getCurrentJob()->getCode());
        }
    }

    /**
     * Check already imported entities are still in Magento.
     *
     * @return void
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function checkEntities(): void
    {
        $connection = $this->entitiesHelper->getConnection();

        $akeneoConnectorTable = $this->entitiesHelper->getTable('akeneo_connector_entities');
        $entityTable = $this->entitiesHelper->getTable('eav_attribute_set');
        $entityTypeTable = $this->entitiesHelper->getTable('eav_entity_type');

        $selectExistingEntities = $connection->select()
            ->from(['eas' => $entityTable], 'eas.attribute_set_id')
            ->joinLeft(['eet' => $entityTypeTable], 'eas.entity_type_id = eet.entity_type_id')
            ->where('eet.entity_type_code = ?', CustomEntityInterface::ENTITY);

        $existingEntities = array_column(
            $connection->query($selectExistingEntities)->fetchAll(),
            'attribute_set_id'
        );

        $connection->delete(
            $akeneoConnectorTable,
            ['import = ?' => 'smile_custom_entity', 'entity_id NOT IN (?)' => $existingEntities]
        );
    }

    /**
     * Match code with entity.
     *
     * @return void
     *
     * @throws Exception
     */
    public function matchEntities(): void
    {
        $this->entitiesHelper->matchEntity(
            'code',
            'eav_attribute_set',
            'attribute_set_id',
            $this->jobExecutor->getCurrentJob()->getCode()
        );
    }

    /**
     * Insert entities.
     *
     * @return void
     *
     * @throws LocalizedException
     */
    public function insertEntity(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $label = 'labels-' . $this->storeHelper->getAdminLang();
        $entityTypeId = $this->eavConfig->getEntityType(CustomEntityInterface::ENTITY)
            ->getEntityTypeId();
        $values = [
            'attribute_set_id' => '_entity_id',
            'entity_type_id' => new Expr($entityTypeId),
            'attribute_set_name' => new Expr('IFNULL(`' . $label . '`, `code`)'),
            'sort_order' => new Expr(1),
        ];

        $entities = $connection->select()->from($tmpTable, $values);

        $connection->query(
            $connection->insertFromSelect(
                $entities,
                $this->entitiesHelper->getTable('eav_attribute_set'),
                array_keys($values),
                1
            )
        );
    }

    /**
     * Init group.
     *
     * @return void
     *
     * @throws LocalizedException
     *
     * @throws Zend_Db_Statement_Exception
     */
    public function initGroup(): void
    {
        $connection = $this->entitiesHelper->getConnection();
        $tmpTable = $this->entitiesHelper->getTableName($this->jobExecutor->getCurrentJob()->getCode());
        $query = $connection->query(
            $connection->select()->from($tmpTable, ['_entity_id'])->where('_is_new = ?', 1)
        );
        $defaultAttributeSetId = $this->eavConfig->getEntityType(CustomEntityInterface::ENTITY)
            ->getDefaultAttributeSetId();

        $count = 0;
        while (($row = $query->fetch())) {
            $attributeSet = $this->attributeSetFactory->create();
            $attributeSet->load($row['_entity_id']);
            if ($attributeSet->hasData()) {
                $attributeSet->initFromSkeleton($defaultAttributeSetId)->save();
            }
            $count++;
        }

        $this->jobExecutor->setMessage(
            __('%1 custom entity(ies) initialized', $count)
        );
    }

    /**
     * Drop temporary table.
     *
     * @return void
     */
    public function dropTable(): void
    {
        $this->entitiesHelper->dropTable($this->jobExecutor->getCurrentJob()->getCode());
    }

    /**
     * Clean cache.
     *
     * @return void
     */
    public function cleanCache(): void
    {
        $types = $this->configManager->getCacheTypeEntity();
        if (empty($types)) {
            $this->jobExecutor->setMessage(__('No cache cleaned'));
            return;
        }
        $cacheTypeLabels = $this->cacheTypeList->getTypeLabels();
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }
        $this->jobExecutor->setMessage(
            __('Cache cleaned for: %1', join(', ', array_intersect_key($cacheTypeLabels, array_flip($types))))
        );
    }

    /**
     * Load reference entities.
     *
     * @return array
     */
    protected function loadReferenceEntitiesFromApi(): array
    {
        $mode = $this->configManager->getFilterMode();
        $api = $this->akeneoClient->getReferenceEntityApi();
        $referenceEntities = [];
        switch ($mode) {
            case Mode::ALL:
                $apiResult = $api->all();
                foreach ($apiResult as $referenceEntity) {
                    $referenceEntities[] = $referenceEntity;
                }
                break;
            case Mode::SPECIFIC:
                $specificEntities = $this->configManager->getFilterEntities();
                foreach ($specificEntities as $entityCode) {
                    $referenceEntity = $api->get($entityCode);
                    $referenceEntities[] = $referenceEntity;
                }
                break;
            default:
                break;
        }
        return $referenceEntities;
    }
}
