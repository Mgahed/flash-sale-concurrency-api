<?php

namespace App\Services;

use App\Models\Hold;

interface HoldServiceInterface
{
    /**
     * Create a hold for a product.
     *
     * @param int $productId
     * @param int $qty
     * @param int|null $userId
     * @return Hold
     * @throws \Exception
     */
    public function createHold(int $productId, int $qty, ?int $userId = null): Hold;

    /**
     * Release an expired or unused hold.
     *
     * @param int $holdId
     * @return bool
     */
    public function releaseHold(int $holdId): bool;

    /**
     * Get expired holds that need to be released.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getExpiredHolds();
}

