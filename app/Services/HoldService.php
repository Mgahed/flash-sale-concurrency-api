<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HoldService implements HoldServiceInterface
{
    private const HOLD_LIFETIME_MINUTES = 2;
    private const LOCK_TIMEOUT = 10;
    private const MAX_RETRIES = 3;

    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * Create a hold for a product.
     */
    public function createHold(int $productId, int $qty, ?int $userId = null): Hold
    {
        if ($qty <= 0) {
            throw new \Exception('Quantity must be greater than 0');
        }

        $lockKey = $this->productService->getLockKey($productId);
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return Cache::lock($lockKey, self::LOCK_TIMEOUT)->block(3, function () use ($productId, $qty, $userId) {
                    return $this->createHoldWithLock($productId, $qty, $userId);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle deadlock
                if ($this->isDeadlock($e)) {
                    $attempt++;
                    Log::warning('Deadlock detected, retrying', [
                        'product_id' => $productId,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    if ($attempt >= self::MAX_RETRIES) {
                        throw new \Exception('Failed to create hold due to high contention. Please try again.');
                    }

                    // Exponential backoff
                    usleep(pow(2, $attempt) * 100000); // 200ms, 400ms, 800ms
                    continue;
                }
                throw $e;
            }
        }

        throw new \Exception('Failed to acquire lock for hold creation');
    }

    /**
     * Create hold within a lock.
     */
    private function createHoldWithLock(int $productId, int $qty, ?int $userId): Hold
    {
        return DB::transaction(function () use ($productId, $qty, $userId) {
            // Lock the product row
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            // CRITICAL: Always calculate from DB to ensure correctness
            // Cache may be stale or corrupted
            $actualAvailableStock = $product->calculateAvailableStock();

            // Get cache key
            $cacheKey = "product:{$productId}:available_stock";
            $cachedStock = Cache::get($cacheKey);

            // If cache differs from DB, refresh it
            if ($cachedStock === null || $cachedStock != $actualAvailableStock) {
                Cache::put($cacheKey, $actualAvailableStock, 300);
                $cachedStock = $actualAvailableStock;

                Log::info('Cache refreshed from DB', [
                    'product_id' => $productId,
                    'actual_stock' => $actualAvailableStock,
                    'old_cached_stock' => $cachedStock,
                ]);
            }

            // Verify sufficient stock (use DB calculation as source of truth)
            if ($actualAvailableStock < $qty) {
                Log::warning('Insufficient stock for hold', [
                    'product_id' => $productId,
                    'requested_qty' => $qty,
                    'available_stock' => $actualAvailableStock,
                ]);
                throw new \Exception('Insufficient stock available');
            }

            // Create the hold
            $expiresAt = Carbon::now()->addMinutes(self::HOLD_LIFETIME_MINUTES);
            $hold = Hold::create([
                'product_id' => $productId,
                'qty' => $qty,
                'expires_at' => $expiresAt,
                'used' => false,
                'released' => false,
            ]);

            // Decrement cached stock atomically
            $newStock = Cache::decrement($cacheKey, $qty);

            Log::info('Hold created successfully', [
                'hold_id' => $hold->id,
                'product_id' => $productId,
                'qty' => $qty,
                'expires_at' => $expiresAt,
                'new_cached_stock' => $newStock,
            ]);

            return $hold;
        });
    }

    /**
     * Release an expired or unused hold.
     */
    public function releaseHold(int $holdId): bool
    {
        $lockKey = "lock:hold:{$holdId}";

        try {
            return Cache::lock($lockKey, 10)->block(3, function () use ($holdId) {
                return DB::transaction(function () use ($holdId) {
                    $hold = Hold::where('id', $holdId)->lockForUpdate()->first();

                    if (!$hold) {
                        Log::warning('Hold not found for release', ['hold_id' => $holdId]);
                        return false;
                    }

                    // Only release if not used and not already released
                    if ($hold->used || $hold->released) {
                        Log::info('Hold already used or released', [
                            'hold_id' => $holdId,
                            'used' => $hold->used,
                            'released' => $hold->released,
                        ]);
                        return false;
                    }

                    // Mark as released
                    $hold->released = true;
                    $hold->save();

                    // Restore stock in cache
                    $cacheKey = "product:{$hold->product_id}:available_stock";
                    $productLockKey = $this->productService->getLockKey($hold->product_id);

                    try {
                        Cache::lock($productLockKey, 5)->block(2, function () use ($cacheKey, $hold) {
                            $currentStock = Cache::get($cacheKey);
                            if ($currentStock !== null) {
                                Cache::increment($cacheKey, $hold->qty);
                            } else {
                                // Refresh from DB if cache is empty
                                $this->productService->refreshCachedStock($hold->product_id);
                            }
                        });
                    } catch (\Exception $e) {
                        // If lock fails, just refresh from DB
                        Log::warning('Failed to acquire product lock for cache update, refreshing from DB', [
                            'product_id' => $hold->product_id,
                            'error' => $e->getMessage(),
                        ]);
                        $this->productService->refreshCachedStock($hold->product_id);
                    }

                    Log::info('Hold released successfully', [
                        'hold_id' => $holdId,
                        'product_id' => $hold->product_id,
                        'qty_restored' => $hold->qty,
                    ]);

                    return true;
                });
            });
        } catch (\Exception $e) {
            Log::error('Failed to release hold', [
                'hold_id' => $holdId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get expired holds that need to be released.
     */
    public function getExpiredHolds()
    {
        return Hold::where('expires_at', '<=', now())
            ->where('used', false)
            ->where('released', false)
            ->get();
    }

    /**
     * Check if exception is a deadlock.
     */
    private function isDeadlock(\Illuminate\Database\QueryException $e): bool
    {
        return in_array($e->getCode(), ['40001', '1213']);
    }
}

