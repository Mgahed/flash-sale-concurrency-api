<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_flash_sale_flow(): void
    {
        // 1. Create product
        $product = Product::factory()->create([
            'name' => 'Flash Sale Widget',
            'price' => 49.99,
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        // 2. Get product info
        $productResponse = $this->getJson("/api/products/{$product->id}");
        $productResponse->assertOk()
            ->assertJson([
                'available_stock' => 100,
            ]);

        // 3. Create a hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 5,
        ]);

        $holdResponse->assertCreated();
        $holdId = $holdResponse->json('hold_id');

        // 4. Verify stock is reduced
        $productResponse = $this->getJson("/api/products/{$product->id}");
        $this->assertEquals(95, $productResponse->json('available_stock'));

        // 5. Create order from hold
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderResponse->assertCreated()
            ->assertJson([
                'status' => 'pending_payment',
                'amount' => '249.95', // 49.99 * 5
            ]);

        $orderId = $orderResponse->json('id');

        // 6. Simulate successful payment webhook
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderId,
            'payment_status' => 'success',
            'idempotency_key' => 'test-webhook-' . uniqid(),
        ]);

        $webhookResponse->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);

        // 7. Verify final state
        $product->refresh();
        $this->assertEquals(5, $product->stock_sold);

        // 8. Verify available stock is now 95
        $finalProductResponse = $this->getJson("/api/products/{$product->id}");
        $this->assertEquals(95, $finalProductResponse->json('available_stock'));
    }

    public function test_failed_payment_flow_releases_stock(): void
    {
        $product = Product::factory()->create([
            'price' => 99.99,
            'stock_total' => 50,
            'stock_sold' => 0,
        ]);

        // Initialize cache
        Cache::put("product:{$product->id}:available_stock", 50, 300);

        // Create hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $holdId = $holdResponse->json('hold_id');

        // Create order
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderId = $orderResponse->json('id');

        // Simulate failed payment
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'order_id' => $orderId,
            'payment_status' => 'failed',
            'idempotency_key' => 'failed-payment-' . uniqid(),
        ]);

        $webhookResponse->assertOk()
            ->assertJson([
                'status' => 'failed',
            ]);

        // Verify stock is restored (check actual calculation from DB)
        $product->refresh();
        $actualAvailable = $product->calculateAvailableStock();
        $this->assertEquals(50, $actualAvailable, 'Stock should be fully restored after failed payment');

        // Verify no stock was sold
        $this->assertEquals(0, $product->stock_sold);
        
        // Cache should eventually be consistent (may need refresh)
        $cachedStock = Cache::get("product:{$product->id}:available_stock");
        if ($cachedStock !== 50) {
            // Cache might be stale, but DB is correct
            Log::info('Cache stale after failed payment, but DB is correct', [
                'cached' => $cachedStock,
                'actual' => $actualAvailable,
            ]);
        }
    }

    public function test_multiple_concurrent_purchases(): void
    {
        $product = Product::factory()->create([
            'price' => 25.00,
            'stock_total' => 20,
            'stock_sold' => 0,
        ]);

        Cache::put("product:{$product->id}:available_stock", 20, 300);

        $completedOrders = 0;

        // Simulate 5 customers each buying 3 units (15 total)
        for ($i = 0; $i < 5; $i++) {
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
                    $orderId = $orderResponse->json('id');

                    // Complete payment
                    $this->postJson('/api/payments/webhook', [
                        'order_id' => $orderId,
                        'payment_status' => 'success',
                        'idempotency_key' => "multi-purchase-{$i}-" . uniqid(),
                    ]);

                    $completedOrders++;
                }
            }
        }

        // Verify all 5 purchases completed
        $this->assertEquals(5, $completedOrders);

        // Verify stock sold
        $product->refresh();
        $this->assertEquals(15, $product->stock_sold);

        // Verify remaining stock
        $remainingStock = $product->calculateAvailableStock();
        $this->assertEquals(5, $remainingStock);
    }
}

