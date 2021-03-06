<?php
/**
 * Copyright © Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Datatrics\Connect\Service\Pixel\VariableCollector;

use Magento\Catalog\Helper\Data;

/**
 * Class Products
 */
class Products
{

    /**
     * @var Data
     */
    private $data;

    /**
     * Products constructor.
     *
     * @param Data $data
     */
    public function __construct(
        Data $data
    ) {
        $this->data = $data;
    }

    /**
     * @return array
     */
    public function execute(): array
    {
        $product = $this->data->getProduct();
        return [
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getFinalPrice(),
        ];
    }
}
