<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookService implements PaymentWebhookServiceInterface
{
    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Handle payment webhook (idempotent and out-of-order safe).
     */
    public function handlePaymentWebhook(array $payload): array
    {
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $paymentStatus = $payload['payment_status'] ?? null;

        if (!$idempotencyKey || !$orderId || !$paymentStatus) {
            throw new \Exception('Missing required webhook fields');
        }

        // Try to insert webhook log (idempotency check)
        try {
            $webhookLog = DB::transaction(function () use ($idempotencyKey, $payload, $orderId, $paymentStatus) {
                // Check if already processed
                $existing = WebhookLog::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    Log::info('Webhook already processed (idempotency)', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                    ]);

                    return [
                        'status' => 'already_processed',
                        'message' => 'Webhook already processed',
                    ];
                }

                // Check if order exists
                $order = Order::find($orderId);

                if (!$order) {
                    // Order doesn't exist yet (out-of-order webhook)
                    Log::warning('Webhook received before order creation', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                    ]);

                    // Store webhook for later reconciliation
                    WebhookLog::create([
                        'idempotency_key' => $idempotencyKey,
                        'payload' => $payload,
                        'status' => 'pending_order',
                        'processed_at' => now(),
                    ]);

                    return [
                        'status' => 'pending_order',
                        'message' => 'Webhook stored, waiting for order creation',
                    ];
                }

                // Process the webhook
                return $this->processWebhook($order, $paymentStatus, $idempotencyKey, $payload);
            });

            return $webhookLog;
        } catch (\Illuminate\Database\QueryException $e) {
            // If unique constraint fails, webhook was already processed
            if ($e->getCode() === '23000') {
                Log::info('Webhook duplicate detected via unique constraint', [
                    'idempotency_key' => $idempotencyKey,
                ]);

                return [
                    'status' => 'already_processed',
                    'message' => 'Webhook already processed',
                ];
            }

            throw $e;
        }
    }

    /**
     * Process webhook for an existing order.
     */
    private function processWebhook(Order $order, string $paymentStatus, string $idempotencyKey, array $payload): array
    {
        // Record webhook log
        WebhookLog::create([
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        if ($paymentStatus === 'success') {
            $this->orderService->markOrderAsPaid($order->id);

            Log::info('Webhook processed: payment successful', [
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'success',
                'message' => 'Payment successful, order marked as paid',
                'order_id' => $order->id,
            ];
        } elseif ($paymentStatus === 'failed') {
            $this->orderService->cancelOrder($order->id);

            Log::info('Webhook processed: payment failed', [
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'failed',
                'message' => 'Payment failed, order cancelled and stock released',
                'order_id' => $order->id,
            ];
        }

        throw new \Exception('Invalid payment status: ' . $paymentStatus);
    }

    /**
     * Reconcile pending webhooks for an order.
     * This should be called after order creation.
     */
    public function reconcilePendingWebhooks(int $orderId): void
    {
        $pendingWebhooks = WebhookLog::where('status', 'pending_order')
            ->get();

        foreach ($pendingWebhooks as $webhookLog) {
            $payload = $webhookLog->payload;

            if (isset($payload['order_id']) && $payload['order_id'] == $orderId) {
                Log::info('Reconciling pending webhook', [
                    'webhook_id' => $webhookLog->id,
                    'order_id' => $orderId,
                ]);

                try {
                    DB::transaction(function () use ($webhookLog, $orderId, $payload) {
                        $order = Order::find($orderId);

                        if ($order) {
                            $paymentStatus = $payload['payment_status'];

                            // Update webhook status
                            $webhookLog->status = 'processed';
                            $webhookLog->save();

                            // Process the payment
                            if ($paymentStatus === 'success') {
                                $this->orderService->markOrderAsPaid($order->id);
                            } elseif ($paymentStatus === 'failed') {
                                $this->orderService->cancelOrder($order->id);
                            }
                        }
                    });
                } catch (\Exception $e) {
                    Log::error('Failed to reconcile pending webhook', [
                        'webhook_id' => $webhookLog->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}

