<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Datatrics\Connect\Model\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Datatrics\Connect\Api\API\AdapterInterface as ApiAdapter;
use Datatrics\Connect\Api\Config\RepositoryInterface as ConfigRepository;
use Magento\Framework\Serialize\Serializer\Json;
use Datatrics\Connect\Model\Content\ResourceModel as ContentResource;
use Datatrics\Connect\Service\Product\Data\AttributeMapper;
use Datatrics\Connect\Api\ProductData\RepositoryInterface as ProductDataRepository;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Symfony\Component\Console\Helper\ProgressBar;
use Datatrics\Connect\Service\Product\Hub;

/**
 * Class ContentUpdate
 *
 * Prepare content data
 */
class ContentUpdate
{

    /**
     * @var ContentResource
     */
    private $contentResource;

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
     * @var AttributeMapper
     */
    private $attributeMapper;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeManager;

    /**
     * @var ProductCollection
     */
    private $productCollection;

    /**
     * @var bool
     */
    private $isDry = false;

    /**
     * @var Hub
     */
    private $collector;

    /**
     * @var ProgressBar
     */
    private $progressBar;

    /**
     * @var array
     */
    private $mediaUrl;

    /**
     * @var array
     */
    private $filters;

    /**
     * @var array
     */
    private $parents;

    /**
     * @var array
     */
    private $types;

    /**
     * @var array
     */
    private $behaviour;

    /**
     * @var array
     */
    private $childOf;

    /**
     * @var array
     */
    private $extraAttributes = [];

    /**
     * @var array
     */
    private $categoryNames = [];

    /**
     * @var array
     */
    private $storeUrl = [];

    /**
     * @var ProductDataRepository
     */
    private $productDataRepository;

    /**
     * ContentUpdate constructor.
     * @param ContentResource $contentResource
     * @param ApiAdapter $apiAdapter
     * @param ConfigRepository $configRepository
     * @param Json $json
     * @param StoreRepositoryInterface $storeManager
     * @param ProductCollection $productCollection
     * @param ProductDataRepository $productDataRepository
     */
    public function __construct(
        ContentResource $contentResource,
        ApiAdapter $apiAdapter,
        ConfigRepository $configRepository,
        Json $json,
        StoreRepositoryInterface $storeManager,
        ProductCollection $productCollection,
        ProductDataRepository $productDataRepository
    ) {
        $this->contentResource = $contentResource;
        $this->apiAdapter = $apiAdapter;
        $this->configRepository = $configRepository;
        $this->json = $json;
        $this->storeManager = $storeManager;
        $this->productCollection = $productCollection;
        $this->productDataRepository = $productDataRepository;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function run(InputInterface $input, OutputInterface $output)
    {
        if (!$this->configRepository->isProductSyncEnabled()) {
            $output->writeln('Product syncronisation disabled');
            return 0;
        }
        $this->isDry = (bool)$input->getOption('dry');
        $storeId = $input->getOption('store-id');

        $connection = $this->contentResource->getConnection();
        $select = $connection->select()->from(
            $connection->getTableName('datatrics_content_store'),
            [
                'product_id',
                'update_attempts'
            ]
        )->where('store_id = ?', $storeId);
        if (!$input->getOption('force')) {
            $select->where('status <> ?', 'Synced');
        }
        if ($productIds = $input->getArgument('product-id')) {
            $select->where('product_id in (?)', $productIds);
        }
        if (!$connection->fetchOne($select)) {
            return 0;
        }
        $productIds = $connection->fetchCol($select, 'product_id');
        $attempts = $connection->fetchPairs($select);
        $this->initProgressBar($output, count($productIds));
        $this->prepareData($productIds, $storeId, $attempts);
        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param string|int $size
     */
    private function initProgressBar($output, $size)
    {
        /* init progress bar */
        $this->progressBar = new \Symfony\Component\Console\Helper\ProgressBar(
            $output,
            $size
        );
        $this->progressBar->setMessage('0', 'content');
        $this->progressBar->setFormat(
            '<info>Content</info> %current%/%max% [%bar%] %percent:3s%% %elapsed% %memory:6s%
    <info>⏺ Pushed:    </info>%content%'
        );
    }

    /**
     * @param array $productIds
     * @param string|int $storeId
     * @param string|int $attempts
     * @return int
     */
    private function prepareData($productIds, $storeId, $attempts)
    {
        $connection = $this->contentResource->getConnection();
        $count = 0;
        $items = [
            'items' => []
        ];
        $data = $this->productDataRepository->getProductData($storeId, $productIds);
        foreach ($data as $id => $product) {
            $preparedData = [
                "itemid" => $id,
                "source" => "Magento 2",
                "item" => $product
            ];
            try {
                $serializedData = $this->json->serialize($preparedData);
            } catch (\Exception $e) {
                continue;
            }
            $items['items'][] = $preparedData;
        }
        if ($this->isDry) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            print_r($items);
            return $count;
        }
        if (!$items['items']) {
            return $count;
        }
        //bulk update
        $response = $this->apiAdapter->execute(
            ApiAdapter::BULK_CREATE_CONTENT,
            null,
            $this->json->serialize($items)
        );
        if ($response['success']) {
            $count += $response['data']['total_elements'];
        }
        $productIds = [];
        foreach ($response['data']['items'] as $item) {
            $productIds[] = $item['id'];
        }
        $where = [
            'product_id IN (?)' => $productIds,
            'store_id = ?' => $storeId
        ];
        if ($response['success']) {
            $connection->update(
                $connection->getTableName('datatrics_content_store'),
                [
                    'status' => 'Synced',
                    'update_msg' => '',
                    'update_attempts' => 0
                ],
                $where
            );
        } else {
            $connection->update(
                $connection->getTableName('datatrics_content_store'),
                [
                    'status' => 'Error',
                    'update_msg' => ''
                ],
                $where
            );
        }
        $this->progressBar->setMessage((string)$count, 'content');
        $this->progressBar->advance($count);

        return $count;
    }
}
