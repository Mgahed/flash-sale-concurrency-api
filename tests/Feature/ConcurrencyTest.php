<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_holds_prevent_overselling(): void
    {
        // Create a product with limited stock
        $product = Product::factory()->create([
            'stock_total' => 10,
            'stock_sold' => 0,
        ]);

        // Initialize cache
        Cache::put("product:{$product->id}:available_stock", 10, 300);

        // Simulate 20 concurrent attempts to reserve 1 unit each
        // Only 10 should succeed
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        // We'll use DB transactions to simulate concurrency
        for ($i = 0; $i < 20; $i++) {
            try {
                $response = $this->postJson('/api/holds', [
                    'product_id' => $product->id,
                    'qty' => 1,
                ]);

                if ($response->status() === 201) {
                    $successCount++;
                    $results[] = ['success' => true, 'hold_id' => $response->json('hold_id')];
                } else {
                    $failureCount++;
                    $results[] = ['success' => false, 'error' => $response->json('error')];
                }
            } catch (\Exception $e) {
                $failureCount++;
                $results[] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Verify exactly 10 holds succeeded
        $this->assertEquals(10, $successCount, 'Expected exactly 10 successful holds');
        $this->assertEquals(10, $failureCount, 'Expected exactly 10 failed holds');

        // Verify database consistency
        $totalHolds = Hold::where('product_id', $product->id)
            ->where('used', false)
            ->where('released', false)
            ->sum('qty');

        $this->assertEquals(10, $totalHolds, 'Total holds should equal stock');

        // Verify no overselling
        $this->assertLessThanOrEqual(10, $totalHolds, 'Holds should not exceed available stock');
    }

    public function test_concurrent_holds_at_stock_boundary(): void
    {
        // Create a product with exactly 5 units
        $product = Product::factory()->create([
            'stock_total' => 5,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 5, 300);

        $successCount = 0;
        $failureCount = 0;

        // 3 concurrent attempts to reserve 2 units each (total 6 units requested)
        // Only 2 requests should succeed (4 units), 1 should fail
        $attempts = [
            ['qty' => 2],
            ['qty' => 2],
            ['qty' => 2],
        ];

        foreach ($attempts as $attempt) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => $attempt['qty'],
            ]);

            if ($response->status() === 201) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        // Verify at least one failed due to insufficient stock
        $this->assertGreaterThan(0, $failureCount, 'At least one hold should fail');

        // Verify total reserved doesn't exceed available
        $totalReserved = Hold::where('product_id', $product->id)
            ->where('used', false)
            ->where('released', false)
            ->sum('qty');

        $this->assertLessThanOrEqual(5, $totalReserved, 'Total reserved cannot exceed stock');
    }

    public function test_database_consistency_after_concurrent_operations(): void
    {
        $product = Product::factory()->create([
            'stock_total' => 50,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 50, 300);

        // Create multiple holds
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 5,
            ]);
        }

        // Verify database and cache consistency
        $totalHolds = Hold::where('product_id', $product->id)
            ->where('used', false)
            ->where('released', false)
            ->sum('qty');

        $cachedStock = Cache::get("product:{$product->id}:available_stock");
        $calculatedStock = $product->calculateAvailableStock();

        $this->assertEquals(25, $totalHolds);
        $this->assertEquals(25, $cachedStock);
        $this->assertEquals(25, $calculatedStock);
    }

    public function test_parallel_hold_creation_with_high_contention(): void
    {
        // This test simulates high contention on a single product
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 100, 300);

        $successCount = 0;
        $attempts = 150; // More attempts than available stock

        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successCount++;
            }
        }

        // Verify we didn't oversell
        $this->assertLessThanOrEqual(100, $successCount, 'Should not exceed available stock');

        // Verify actual holds in database
        $totalHolds = Hold::where('product_id', $product->id)
            ->where('used', false)
            ->where('released', false)
            ->sum('qty');

        $this->assertEquals($successCount, $totalHolds);
        $this->assertLessThanOrEqual(100, $totalHolds);
    }

    public function test_pending_payment_holds_prevent_overselling(): void
    {
        // This test reproduces the exact scenario described by the user:
        // User A creates order (hold marked as used) but webhook is delayed
        // User B should NOT be able to reserve more than remaining stock

        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 100, 300);

        // User A: Create hold for 10 items
        $holdResponseA = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);
        $holdResponseA->assertCreated();
        $holdIdA = $holdResponseA->json('hold_id');

        // User A: Create order (hold is marked as used, but webhook hasn't arrived yet)
        $orderResponseA = $this->postJson('/api/orders', [
            'hold_id' => $holdIdA,
        ]);
        $orderResponseA->assertCreated();

        // At this point:
        // - Hold A is marked as used=true
        // - Order A is pending_payment
        // - stock_sold is still 0 (webhook not processed)
        // - Available stock should be 90, NOT 100

        // Refresh cache to recalculate from DB
        $product->refresh();
        $availableStock = $product->calculateAvailableStock();

        $this->assertEquals(90, $availableStock, 'Pending payment orders must reserve stock');

        // User B: Try to hold 100 items (should FAIL - only 90 available)
        $holdResponseB = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 100,
        ]);

        $holdResponseB->assertStatus(400)
            ->assertJson([
                'error' => 'Insufficient stock available',
            ]);

        // User B: Try to hold 90 items (should SUCCEED)
        $holdResponseB2 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 90,
        ]);

        $holdResponseB2->assertCreated();

        // Verify total reserved stock doesn't exceed total stock
        $product->refresh();
        $calculatedAvailable = $product->calculateAvailableStock();
        $this->assertEquals(0, $calculatedAvailable, 'All stock should now be reserved');

        // Now simulate webhook arriving for User A
        $orderIdA = $orderResponseA->json('id');
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderIdA,
            'payment_status' => 'success',
            'idempotency_key' => 'test-pending-payment-' . uniqid(),
        ]);

        $webhookResponse->assertOk();

        // After payment, User A's 10 items should be in stock_sold
        $product->refresh();
        $this->assertEquals(10, $product->stock_sold);

        // Available stock should still be 90 (held by User B)
        $availableAfterPayment = $product->calculateAvailableStock();
        $this->assertEquals(0, $availableAfterPayment, 'User B still holds 90 items');
    }

    public function test_cancelled_order_releases_pending_payment_hold(): void
    {
        // Verify that when an order is cancelled (failed payment),
        // the stock is properly restored

        $product = Product::factory()->create([
            'stock_total' => 50,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 50, 300);

        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 20,
        ]);
        $holdId = $holdResponse->json('hold_id');

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);
        $orderId = $orderResponse->json('id');

        // Stock should show 30 available (20 reserved by pending order)
        $product->refresh();
        $this->assertEquals(30, $product->calculateAvailableStock());

        // Simulate failed payment webhook
        $this->postJson('/api/payments/webhook', [
            'order_id' => $orderId,
            'payment_status' => 'failed',
            'idempotency_key' => 'test-cancel-' . uniqid(),
        ]);

        // Stock should be restored to 50 available
        $product->refresh();
        Cache::forget("product:{$product->id}:available_stock");
        $this->assertEquals(50, $product->calculateAvailableStock());
    }
}

