<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoldServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    public function __construct(
        private HoldServiceInterface $holdService
    ) {}

    /**
     * Create a hold.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        try {
            $hold = $this->holdService->createHold(
                $request->input('product_id'),
                $request->input('qty')
            );

            return response()->json([
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at->toIso8601String(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

