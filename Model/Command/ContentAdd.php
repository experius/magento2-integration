<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Datatrics\Connect\Model\Command;

use Datatrics\Connect\Api\Content\RepositoryInterface as ContentRepository;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Datatrics\Connect\Model\Content\ResourceModel as ContentResource;

/**
 * Class ContentAdd
 *
 * Prepare content data
 */
class ContentAdd
{

    /**
     * @var ContentRepository
     */
    private $contentRepository;

    /**
     * @var ContentResource
     */
    private $contentResource;

    /**
     * ContentAdd constructor.
     * @param ContentRepository $contentRepository
     * @param ContentResource $contentResource
     */
    public function __construct(
        ContentRepository $contentRepository,
        ContentResource $contentResource
    ) {
        $this->contentRepository = $contentRepository;
        $this->contentResource = $contentResource;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function run(InputInterface $input, OutputInterface $output)
    {
        $connection = $this->contentResource->getConnection();
        $selectStores = $connection->select()->from(
            $connection->getTableName('store'),
            'store_id'
        );
        $stores = [];
        foreach ($connection->fetchAll($selectStores) as $store) {
            $stores[] = $store['store_id'];
        }
        $selectContent = $connection->select()->from(
            $connection->getTableName('datatrics_content'),
            'content_id'
        );
        $select = $connection->select()->from(
            $connection->getTableName('catalog_product_entity'),
            'entity_id'
        )->joinLeft(
            ['super_link' => $connection->getTableName('catalog_product_super_link')],
            'super_link.product_id =' . $connection->getTableName('catalog_product_entity') . '.entity_id',
            [
                'parent_id' => 'GROUP_CONCAT(parent_id)'
            ]
        )->where('entity_id not in (?)', $selectContent)
            ->group('entity_id')->limit(50000);
        $result = $connection->fetchAll($select);

        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($output, count($result));
        $progressBar->setMessage('0', 'product');
        $progressBar->setFormat(
            '<info>Content</info> %current%/%max% [%bar%] %percent:3s%% %elapsed% %memory:6s%
    <info>⏺ Created:    </info>%product%'
        );
        $output->writeln('<info>Adding content.</info>');
        $progressBar->start();
        $progressBar->display();
        $count = 0;
        $this->contentResource->beginTransaction();
        $pool = 0;
        $data = [];
        foreach ($result as $entity) {
            $count++;
            $pool++;
            $content = $this->contentRepository->create();
            $content->setContentId($entity['entity_id'])
                ->setParentId((string)$entity['parent_id']);
            if ($pool == 1000) {
                $pool = 0;
                $progressBar->setMessage((string)$count, 'product');
                $progressBar->advance(1000);
            }
            foreach ($stores as $store) {
                $data[] = [
                    $entity['entity_id'],
                    $store,
                    'Queued for Update'
                ];
            }
            $this->contentRepository->save($content);
        }
        $progressBar->setMessage((string)$count, 'product');
        if ($data) {
            $connection->insertArray(
                $connection->getTableName('datatrics_content_store'),
                ['product_id', 'store_id', 'status'],
                $data
            );
        }
        $this->contentResource->commit();
        $progressBar->finish();
        $output->writeln('');
        return $count;
    }
}
