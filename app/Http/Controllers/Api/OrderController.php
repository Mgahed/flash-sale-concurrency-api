<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderServiceInterface;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function __construct(
        private OrderServiceInterface $orderService,
        private PaymentWebhookService $paymentWebhookService
    ) {}

    /**
     * Create an order from a hold.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|integer|exists:holds,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $order = $this->orderService->createOrderFromHold(
                $request->input('hold_id')
            );

            // Reconcile any pending webhooks for this order (out-of-order safety)
            $this->paymentWebhookService->reconcilePendingWebhooks($order->id);

            return response()->json([
                'id' => $order->id,
                'hold_id' => $order->hold_id,
                'status' => $order->status,
                'amount' => $order->amount,
                'created_at' => $order->created_at->toIso8601String(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

