<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Datatrics\Connect\Service\ProductData\AttributeCollector\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * Service class for category path for products
 */
class Category
{

    /**
     *
     */
    const REQIURE = [
        'entity_ids',
        'store_id'
    ];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var array[]
     */
    private $entityIds;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * @var string
     */
    private $storeId;

    /**
     * @var string
     */
    private $format;

    /**
     * @var array
     */
    private $categoryNames = [];

    /**
     * @var array
     */
    private $categoryIds = [];

    /**
     * @var bool
     */
    private $isCommerce;

    /**
     * Category constructor.
     *
     * @param ResourceConnection $resource
     * @param StoreRepositoryInterface $storeRepository
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        ResourceConnection $resource,
        StoreRepositoryInterface $storeRepository,
        ProductMetadataInterface $productMetadata
    ) {
        $this->resource = $resource;
        $this->storeRepository = $storeRepository;
        $this->isCommerce = ($productMetadata->getEdition() !== ProductMetadata::EDITION_NAME);
    }

    /**
     * Get array of products with path of all assigned categories
     *
     * Structure of response
     * [product_id] = [path1, path2, ..., pathN]
     *
     * @param array[] $entityIds array of product IDs
     * @return array[]
     */
    public function execute($entityIds = [], string $storeId = '1', $format = 'raw'): array
    {
        $this->setData('entity_ids', $entityIds);
        $this->setData('store_id', $storeId);
        $this->setData('format', $format);
        $this->fetchCategoryNames();
        $data = $this->collectCategories();
        $data = $this->mergeNames($data);
        return $this->mergeUrl($data);
    }

    /**
     * @param array $data
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function mergeUrl($data)
    {
        $baseUrl = $this->storeRepository->getById((int)$this->storeId)->getBaseUrl();
        $select = $this->resource->getConnection()
            ->select()
            ->from(
                ['url_rewrite' => $this->resource->getTableName('url_rewrite')],
                ['entity_id', 'request_path']
            )->where('entity_id IN (?)', $this->categoryIds);
        $url = $this->resource->getConnection()->fetchPairs($select);
        foreach ($data as &$datum) {
            foreach ($datum as &$item) {
                if (array_key_exists($item['categoryid'], $url)) {
                    $item['url'] = $baseUrl . $url[$item['categoryid']];
                }
            }
        }
        return $data;
    }

    /**
     * @return array
     */
    public function getRequiredParameters()
    {
        return self::REQIURE;
    }

    /**
     * @param string $type
     */
    public function resetData($type = 'all')
    {
        if ($type == 'all') {
            unset($this->entityIds);
            unset($this->storeId);
        }
        switch ($type) {
            case 'entity_ids':
                unset($this->entityIds);
                break;
            case 'store_id':
                unset($this->storeId);
                break;
            case 'format':
                unset($this->format);
                break;
        }
    }

    /**
     * @param string $type
     * @param mixed $data
     */
    public function setData($type, $data)
    {
        if (!$data) {
            return;
        }
        switch ($type) {
            case 'entity_ids':
                $this->entityIds = $data;
                break;
            case 'store_id':
                $this->storeId = $data;
                break;
            case 'format':
                $this->format = $data;
                break;
        }
    }

    /**
     * Get path data assigned to products
     *
     * @return array[]
     */
    private function collectCategories()
    {
        $path = [];
        $select = $this->resource->getConnection()
            ->select()
            ->from(
                ['catalog_category_product' => $this->resource->getTableName('catalog_category_product')],
                'product_id'
            )->joinLeft(
                ['catalog_category_entity' => $this->resource->getTableName('catalog_category_entity')],
                'catalog_category_entity.entity_id = catalog_category_product.category_id',
                'path'
            )->where('product_id IN (?)', $this->entityIds);
        $result = $this->resource->getConnection()->fetchAll($select);
        foreach ($result as $item) {
            $path[$item['product_id']][] = $item['path'];
        }
        return $path;
    }

    /**
     * Collect categories name according store IDs
     */
    private function fetchCategoryNames()
    {
        if ($this->isCommerce) {
            $fields = ['entity_id' => 'row_id', 'value', 'store_id'];
        } else {
            $fields = ['entity_id', 'value', 'store_id'];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from(
            ['eav_attribute' => $connection->getTableName('eav_attribute')],
            []
        )->joinLeft(
            ['catalog_category_entity_varchar' => $connection->getTableName('catalog_category_entity_varchar')],
            'catalog_category_entity_varchar.attribute_id = eav_attribute.attribute_id',
            $fields
        )->where('eav_attribute.attribute_code = ?', 'name')
        ->where('catalog_category_entity_varchar.store_id IN (?)', [0, $this->storeId]);
        foreach ($connection->fetchAll($select) as $item) {
            $this->categoryNames[$item['entity_id']][$item['store_id']] = $item['value'];
        }
    }

    /**
     * @param array $data
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function mergeNames($data)
    {
        $result = [];
        $rootCategoryId = $this->storeRepository->getById($this->storeId)->getRootCategoryId();
        foreach ($data as $entityId => $categoryPathes) {
            $usedPath = [];
            foreach ($categoryPathes as $categoryPath) {
                $categoryIds = explode('/', $categoryPath);
                $key = array_search($rootCategoryId, $categoryIds);
                if ($key) {
                    $categoryIds = array_slice($categoryIds, $key + 1, count($categoryIds) - $key);
                }
                $level = count($categoryIds);
                if ($level == 0) {
                    continue;
                }
                $categoryNames = [];
                foreach ($categoryIds as $categoryId) {
                    if (!array_key_exists($categoryId, $this->categoryNames)) {
                        continue;
                    }
                    if (!array_key_exists($this->storeId, $this->categoryNames[$categoryId])) {
                        $categoryNames[] = $this->categoryNames[$categoryId][0];
                    } else {
                        $categoryNames[] = $this->categoryNames[$categoryId][$this->storeId];
                    }
                }
                if ($this->format == 'raw') {
                    $path = implode(' > ', $categoryNames);
                    if (!in_array($path, $usedPath)) {
                        if (!in_array(end($categoryIds), $this->categoryIds)) {
                            $this->categoryIds[] = end($categoryIds);
                        }
                        $result[$entityId][] = [
                            'name' => $path,
                            'categoryid' => end($categoryIds)
                        ];
                    }
                    $usedPath[] = $path;
                } else {
                    $path = implode(' > ', $categoryNames);
                    $result[$entityId][] = $path;
                }
            }
        }
        return $result;
    }
}
