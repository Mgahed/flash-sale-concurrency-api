<?php

namespace App\Services;

use App\Models\Order;

interface OrderServiceInterface
{
    /**
     * Create an order from a hold.
     *
     * @param int $holdId
     * @return Order
     * @throws \Exception
     */
    public function createOrderFromHold(int $holdId): Order;

    /**
     * Mark order as paid.
     *
     * @param int $orderId
     * @return Order
     */
    public function markOrderAsPaid(int $orderId): Order;

    /**
     * Cancel order and release stock.
     *
     * @param int $orderId
     * @return Order
     */
    public function cancelOrder(int $orderId): Order;
}

