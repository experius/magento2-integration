<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Datatrics\Connect\Model\Cron;

use Datatrics\Connect\Api\API\AdapterInterface as ApiAdapter;
use Datatrics\Connect\Api\Config\RepositoryInterface as ConfigRepository;
use Datatrics\Connect\Model\Profile\CollectionFactory as ProfileCollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Class ProfileUpdate
 *
 * Sync customer's data with platform
 */
class ProfileUpdate
{

    /**
     * @var ProfileCollectionFactory
     */
    private $profileCollectionFactory;

    /**
     * @var ApiAdapter
     */
    private $apiAdapter;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var Json
     */
    private $json;

    /**
     * ProfileUpdate constructor.
     * @param ProfileCollectionFactory $profileCollectionFactory
     * @param ApiAdapter $apiAdapter
     * @param ConfigRepository $configRepository
     * @param Json $json
     */
    public function __construct(
        ProfileCollectionFactory $profileCollectionFactory,
        ApiAdapter $apiAdapter,
        ConfigRepository $configRepository,
        Json $json
    ) {
        $this->profileCollectionFactory = $profileCollectionFactory;
        $this->apiAdapter = $apiAdapter;
        $this->configRepository = $configRepository;
        $this->json = $json;
    }

    /**
     * Push customer's data to platform
     *
     * @return $this
     */
    public function execute()
    {
        if (!$this->configRepository->isEnabled()
            || !$this->configRepository->getCustomerSyncEnabled()
        ) {
            return $this;
        }
        $collection = $this->profileCollectionFactory->create()
            ->addFieldToFilter('status', ['neq' => 'Synced']);
        foreach ($collection as $profile) {
            $response = $this->apiAdapter->execute(
                ApiAdapter::CREATE_PROFILE,
                null,
                $this->json->serialize($this->prepareData($profile))
            );
            if ($response['success']) {
                $profile->setStatus('Synced')->save();
            } else {
                $profile->setStatus('Error')->save();
                $profile->setUpdateAttempts($profile->getUpdateAttempts() + 1)->save();
            }
        }
        return $this;
    }

    /**
     * Prepare data to push
     *
     * @param \Datatrics\Connect\Model\Profile\Data $profile
     * @return array
     */
    private function prepareData($profile)
    {
        return [
            "projectid" => $this->configRepository->getProjectId(),
            "profileid" => $profile->getProfileId(),
            "objecttype" => "profile",
            "source" => 'Magento2',
            "profile" => $profile->getData()
        ];
    }
}
