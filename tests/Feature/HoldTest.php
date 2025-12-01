<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_hold(): void
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'hold_id',
                'expires_at',
            ]);

        $this->assertDatabaseHas('holds', [
            'product_id' => $product->id,
            'qty' => 10,
            'used' => false,
            'released' => false,
        ]);
    }

    public function test_cannot_create_hold_with_insufficient_stock(): void
    {
        $product = Product::factory()->create([
            'stock_total' => 5,
            'stock_sold' => 0,
        ]);

        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Insufficient stock available',
            ]);
    }

    public function test_hold_decrements_available_stock(): void
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        // Create initial cache
        Cache::put("product:{$product->id}:available_stock", 100, 300);

        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 25,
        ]);

        // Check cached stock
        $cachedStock = Cache::get("product:{$product->id}:available_stock");
        $this->assertEquals(75, $cachedStock);
    }

    public function test_expired_holds_are_released_by_command(): void
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        // Create a hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 20,
            'expires_at' => now()->subMinutes(5), // Already expired
            'used' => false,
            'released' => false,
        ]);

        // Set cache
        Cache::put("product:{$product->id}:available_stock", 80, 300);

        // Run expiry command
        Artisan::call('holds:expire');

        // Process the queued job
        $this->artisan('queue:work --once');

        // Verify hold is marked as released
        $hold->refresh();
        $this->assertTrue($hold->released);

        // Verify stock is restored in cache
        $cachedStock = Cache::get("product:{$product->id}:available_stock");
        $this->assertEquals(100, $cachedStock);
    }

    public function test_validation_errors_for_invalid_hold_data(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => 999,
            'qty' => -5,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'messages',
            ]);
    }
}

