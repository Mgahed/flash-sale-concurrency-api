<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_order_from_valid_hold(): void
    {
        $product = Product::factory()->create([
            'price' => 100.00,
            'stock_total' => 50,
            'stock_sold' => 0,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 5,
            'expires_at' => now()->addMinutes(2),
            'used' => false,
            'released' => false,
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'id',
                'hold_id',
                'status',
                'amount',
                'created_at',
            ])
            ->assertJson([
                'status' => 'pending_payment',
                'amount' => '500.00', // 100 * 5
            ]);

        // Verify hold is marked as used
        $hold->refresh();
        $this->assertTrue($hold->used);
    }

    public function test_cannot_create_order_from_expired_hold(): void
    {
        $product = Product::factory()->create();

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 5,
            'expires_at' => now()->subMinutes(1), // Expired
            'used' => false,
            'released' => false,
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Hold is expired and cannot be used',
            ]);
    }

    public function test_cannot_create_order_from_used_hold(): void
    {
        $product = Product::factory()->create();

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 5,
            'expires_at' => now()->addMinutes(2),
            'used' => true, // Already used
            'released' => false,
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Hold is already used and cannot be used',
            ]);
    }

    public function test_cannot_create_order_from_released_hold(): void
    {
        $product = Product::factory()->create();

        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 5,
            'expires_at' => now()->addMinutes(2),
            'used' => false,
            'released' => true, // Already released
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Hold is already released and cannot be used',
            ]);
    }
}

