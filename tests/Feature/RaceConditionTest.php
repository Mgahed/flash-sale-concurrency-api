<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for race condition scenarios and edge cases
 * that could lead to overselling or stock inconsistencies.
 */
class RaceConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_delay_does_not_allow_overselling(): void
    {
        // Critical test: Reproduces the exact bug reported by user
        // Scenario: User A creates order but webhook is delayed,
        // User B should not be able to oversell

        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 100, 300);

        // ============================================
        // User A's Journey (Webhook Delayed)
        // ============================================

        // Step 1: User A creates hold for 10 items
        $holdResponseA = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $holdResponseA->assertCreated();
        $holdIdA = $holdResponseA->json('hold_id');

        // Verify stock is reduced
        $this->assertEquals(90, Cache::get("product:{$product->id}:available_stock"));

        // Step 2: User A creates order (simulating payment initiated)
        $orderResponseA = $this->postJson('/api/orders', [
            'hold_id' => $holdIdA,
        ]);

        $orderResponseA->assertCreated();
        $orderIdA = $orderResponseA->json('id');

        // At this point:
        // - Hold is marked as used=true
        // - Order status is pending_payment
        // - Webhook has NOT arrived yet
        // - stock_sold is still 0

        // Step 3: Verify hold is marked as used
        $hold = Hold::find($holdIdA);
        $this->assertTrue($hold->used);
        $this->assertFalse($hold->released);

        // ============================================
        // CRITICAL CHECK: Available Stock Calculation
        // ============================================

        // The bug: Without the fix, this would return 100
        // With the fix: Should return 90 (User A's items still reserved)
        $product->refresh();
        $availableStock = $product->calculateAvailableStock();

        $this->assertEquals(90, $availableStock, 
            'Pending payment orders MUST still reserve stock to prevent overselling');

        // ============================================
        // User B's Journey (Attempts Overselling)
        // ============================================

        // User B tries to hold all remaining stock
        // Without the fix: This would succeed (incorrectly)
        // With the fix: This should fail with insufficient stock
        $holdResponseB = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 100,
        ]);

        $holdResponseB->assertStatus(400)
            ->assertJson([
                'error' => 'Insufficient stock available',
            ]);

        // User B tries to hold the actual remaining stock (90 items)
        $holdResponseB2 = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 90,
        ]);

        $holdResponseB2->assertCreated();
        $holdIdB = $holdResponseB2->json('hold_id');

        // Verify all stock is now reserved
        $product->refresh();
        $this->assertEquals(0, $product->calculateAvailableStock());

        // ============================================
        // Webhook Finally Arrives for User A
        // ============================================

        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderIdA,
            'payment_status' => 'success',
            'idempotency_key' => 'test-delay-' . uniqid(),
        ]);

        $webhookResponse->assertOk();

        // Verify final state
        $product->refresh();
        $this->assertEquals(10, $product->stock_sold, 'User A purchased 10 items');
        $this->assertEquals(0, $product->calculateAvailableStock(), 'User B still holds 90');

        // Total check: 10 sold + 90 held = 100 (exactly stock_total)
        $totalReserved = $product->stock_sold + Hold::where('product_id', $product->id)
            ->where('used', false)
            ->where('released', false)
            ->sum('qty');

        $this->assertEquals(100, $totalReserved);
        $this->assertLessThanOrEqual($product->stock_total, $totalReserved, 'No overselling!');
    }

    public function test_multiple_pending_payments_all_reserve_stock(): void
    {
        // Test that multiple pending payment orders all correctly reserve stock

        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 100, 300);

        // Create 5 orders, each with 10 items, all pending payment
        $orderIds = [];

        for ($i = 0; $i < 5; $i++) {
            // Create hold
            $holdResponse = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 10,
            ]);

            $holdResponse->assertCreated();
            $holdId = $holdResponse->json('hold_id');

            // Create order
            $orderResponse = $this->postJson('/api/orders', [
                'hold_id' => $holdId,
            ]);

            $orderResponse->assertCreated();
            $orderIds[] = $orderResponse->json('id');
        }

        // All 5 orders are pending payment (50 items reserved)
        $product->refresh();
        $availableStock = $product->calculateAvailableStock();

        $this->assertEquals(50, $availableStock, 
            'All pending payment orders should reserve stock');

        // Try to hold more than available (should fail)
        $overHoldResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 51,
        ]);

        $overHoldResponse->assertStatus(400);

        // Process webhooks for 3 orders (success)
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/payments/webhook', [
                'order_id' => $orderIds[$i],
                'payment_status' => 'success',
                'idempotency_key' => 'multi-test-' . $i,
            ]);
        }

        // Process webhooks for 2 orders (failed)
        for ($i = 3; $i < 5; $i++) {
            $this->postJson('/api/payments/webhook', [
                'order_id' => $orderIds[$i],
                'payment_status' => 'failed',
                'idempotency_key' => 'multi-test-' . $i,
            ]);
        }

        // Final state check
        $product->refresh();
        $this->assertEquals(30, $product->stock_sold, '3 orders * 10 items = 30 sold');
        $this->assertEquals(70, $product->calculateAvailableStock(), 
            '2 failed orders released their 20 items');
    }

    public function test_rapid_order_creation_prevents_overselling(): void
    {
        // Simulate rapid order creation from multiple users
        // All creating orders before any webhooks arrive

        $product = Product::factory()->create([
            'stock_total' => 20,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 20, 300);

        $successfulOrders = 0;

        // 10 users each try to buy 3 items (total 30 requested, only 20 available)
        for ($i = 0; $i < 10; $i++) {
            // Create hold
            $holdResponse = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 3,
            ]);

            if ($holdResponse->status() === 201) {
                $holdId = $holdResponse->json('hold_id');

                // Create order
                $orderResponse = $this->postJson('/api/orders', [
                    'hold_id' => $holdId,
                ]);

                if ($orderResponse->status() === 201) {
                    $successfulOrders++;
                }
            }
        }

        // Should only succeed for 6 users (6 * 3 = 18 items)
        // or 7 users maximum (7 * 3 = 21 would fail on the last)
        $this->assertLessThanOrEqual(6, $successfulOrders, 
            'Should not create more orders than available stock allows');

        // Verify no overselling in database
        $product->refresh();
        $totalPendingQty = Hold::where('product_id', $product->id)
            ->where('used', true)
            ->where('released', false)
            ->whereHas('order', function ($query) {
                $query->where('status', 'pending_payment');
            })
            ->sum('qty');

        $this->assertLessThanOrEqual($product->stock_total, $totalPendingQty,
            'Pending payment quantity should not exceed stock total');
    }

    public function test_cache_inconsistency_corrected_by_db(): void
    {
        // Test that even if cache becomes inconsistent,
        // DB calculations prevent overselling

        $product = Product::factory()->create([
            'stock_total' => 50,
            'stock_sold' => 0,
        ]);

        // Simulate cache corruption (shows more stock than reality)
        Cache::put("product:{$product->id}:available_stock", 1000, 300);

        // Create hold - should recalculate from DB despite bad cache
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 60, // More than actual stock
        ]);

        // Should fail because DB calculation overrides cache
        $holdResponse->assertStatus(400);

        // Verify cache was corrected
        $correctedCache = Cache::get("product:{$product->id}:available_stock");
        $this->assertEquals(50, $correctedCache, 'Cache should be corrected from DB');
    }
}

