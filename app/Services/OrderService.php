<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        private HoldService $holdService
    ) {}

    /**
     * Create an order from a hold.
     */
    public function createOrderFromHold(int $holdId): Order
    {
        return DB::transaction(function () use ($holdId) {
            // Lock the hold
            $hold = Hold::where('id', $holdId)->with('product')->lockForUpdate()->firstOrFail();

            // Validate hold
            if (!$hold->isValid()) {
                $reason = $hold->used ? 'already used' : 
                         ($hold->released ? 'already released' : 'expired');
                
                Log::warning('Invalid hold for order creation', [
                    'hold_id' => $holdId,
                    'reason' => $reason,
                ]);
                
                throw new \Exception("Hold is {$reason} and cannot be used");
            }

            // Mark hold as used
            $hold->used = true;
            $hold->save();

            // Create order
            $amount = $hold->product->price * $hold->qty;
            $order = Order::create([
                'hold_id' => $hold->id,
                'status' => 'pending_payment',
                'amount' => $amount,
            ]);

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $hold->id,
                'amount' => $amount,
            ]);

            return $order;
        });
    }

    /**
     * Mark order as paid.
     */
    public function markOrderAsPaid(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if ($order->status === 'paid') {
                Log::info('Order already paid', ['order_id' => $orderId]);
                return $order;
            }

            $order->status = 'paid';
            $order->save();

            // Increment stock_sold for the product
            $hold = $order->hold;
            DB::table('products')
                ->where('id', $hold->product_id)
                ->increment('stock_sold', $hold->qty);

            Log::info('Order marked as paid', [
                'order_id' => $orderId,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);

            return $order;
        });
    }

    /**
     * Cancel order and release stock.
     */
    public function cancelOrder(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled') {
                Log::info('Order already cancelled', ['order_id' => $orderId]);
                return $order;
            }

            if ($order->status === 'paid') {
                throw new \Exception('Cannot cancel a paid order');
            }

            $order->status = 'cancelled';
            $order->save();

            // Release the hold to restore stock
            $this->holdService->releaseHold($order->hold_id);

            Log::info('Order cancelled', [
                'order_id' => $orderId,
                'hold_id' => $order->hold_id,
            ]);

            return $order;
        });
    }
}

