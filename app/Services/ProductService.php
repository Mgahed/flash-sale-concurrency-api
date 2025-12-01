<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductService implements ProductServiceInterface
{
    private const CACHE_TTL = 300; // 5 minutes
    private const LOCK_TIMEOUT = 5;

    /**
     * Get product with available stock (cached).
     */
    public function getProductWithStock(int $productId): array
    {
        $product = Product::findOrFail($productId);
        $availableStock = $this->getAvailableStock($productId);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'stock_total' => $product->stock_total,
            'stock_sold' => $product->stock_sold,
            'available_stock' => $availableStock,
        ];
    }

    /**
     * Get available stock for a product (from cache or DB).
     */
    public function getAvailableStock(int $productId): int
    {
        $cacheKey = $this->getStockCacheKey($productId);

        $stock = Cache::get($cacheKey);

        if ($stock === null) {
            Log::info('Cache miss for product stock', ['product_id' => $productId]);
            $stock = $this->refreshCachedStock($productId);
        }

        return max(0, $stock);
    }

    /**
     * Refresh cached stock for a product.
     */
    public function refreshCachedStock(int $productId): int
    {
        $product = Product::findOrFail($productId);
        $availableStock = $product->calculateAvailableStock();

        $cacheKey = $this->getStockCacheKey($productId);
        Cache::put($cacheKey, $availableStock, self::CACHE_TTL);

        Log::info('Refreshed cached stock', [
            'product_id' => $productId,
            'available_stock' => $availableStock,
        ]);

        return $availableStock;
    }

    /**
     * Get cache key for product stock.
     */
    private function getStockCacheKey(int $productId): string
    {
        return "product:{$productId}:available_stock";
    }

    /**
     * Get lock key for product.
     */
    public function getLockKey(int $productId): string
    {
        return "lock:product:{$productId}";
    }
}

