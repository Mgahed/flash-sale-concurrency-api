<?php

namespace App\Services;

use App\Models\Product;

interface ProductServiceInterface
{
    /**
     * Get product with available stock (cached).
     *
     * @param int $productId
     * @return array
     */
    public function getProductWithStock(int $productId): array;

    /**
     * Get available stock for a product (from cache or DB).
     *
     * @param int $productId
     * @return int
     */
    public function getAvailableStock(int $productId): int;

    /**
     * Refresh cached stock for a product.
     *
     * @param int $productId
     * @return int
     */
    public function refreshCachedStock(int $productId): int;
}

