<?php

declare(strict_types=1);

namespace Smile\CustomEntityAkeneo\Setup\Patch\Data;

use Akeneo\Connector\Api\Data\JobInterface;
use Akeneo\Connector\Model\Job;
use Akeneo\Connector\Model\JobFactory;
use Akeneo\Connector\Model\JobRepository;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Smile\CustomEntityAkeneo\Job\Attribute;
use Smile\CustomEntityAkeneo\Job\CustomEntity;
use Smile\CustomEntityAkeneo\Job\CustomEntityRecord;
use Smile\CustomEntityAkeneo\Job\Option;

/**
 * Create jobs for custom(reference) entities import.
 */
class CreateJobs implements DataPatchInterface
{
    /**
     * Module Data Setup.
     */
    protected ModuleDataSetupInterface $moduleDataSetup;

    /**
     * Akeneo connector job repository.
     */
    protected JobRepository $jobRepository;

    /**
     * Akeneo connector job factory.
     */
    protected JobFactory $jobFactory;

    /**
     * Constructor.
     */
    public function __construct(
        ModuleDataSetupInterface $dataSetup,
        JobRepository $jobRepository,
        JobFactory $jobFactory
    ) {
        $this->jobRepository = $jobRepository;
        $this->jobFactory = $jobFactory;
        $this->moduleDataSetup = $dataSetup;
    }

    /**
     * Apply function.
     *
     * @return CreateJobs
     * @throws AlreadyExistsException
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $this->updateExistingJobs();

        $index = 0;

        foreach ($this->getJobsData() as $code => $data) {
            /** @var Job $job */
            $job = $this->jobFactory->create();
            $job->setCode($code);
            $job->setPosition($index);
            $job->setStatus(JobInterface::JOB_PENDING);
            $job->setName($data['name']);
            $job->setJobClass($data['class']);
            $this->jobRepository->save($job);
            $index++;
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * Update existing job position to execute them after custom entity import.
     *
     * @throws AlreadyExistsException
     * @SuppressWarnings(PHPMD)
     */
    protected function updateExistingJobs(): void
    {
        $jobs = $this->jobRepository->getList();
        $index = count($this->getJobsData());
        foreach ($jobs->getItems() as $job) {
            $job->setPosition($job->getPosition() + $index);
            /** @phpstan-ignore-next-line */
            $this->jobRepository->save($job);
        }
    }

    /**
     * Return job data.
     *
     * @return string[][]
     */
    protected function getJobsData(): array
    {
        return [
            'smile_custom_entity' => [
                'class' => CustomEntity::class,
                'name' => 'Smile custom entity',
            ],
            'smile_custom_entity_attribute' => [
                'class' => Attribute::class,
                'name' => 'Smile custom entity attribute',
            ],
            'smile_custom_entity_attribute_option' => [
                'class' => Option::class,
                'name' => 'Smile custom entity attribute option',
            ],
            'smile_custom_entity_record' => [
                'class' => CustomEntityRecord::class,
                'name' => 'Smile custom entity',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [
            \Akeneo\Connector\Setup\Patch\Data\CreateJobs::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
