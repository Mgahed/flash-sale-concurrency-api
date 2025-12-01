<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_get_product_with_stock(): void
    {
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'price' => 99.99,
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertOk()
            ->assertJson([
                'id' => $product->id,
                'name' => 'Test Product',
                'price' => '99.99',
                'stock_total' => 100,
                'stock_sold' => 0,
                'available_stock' => 100,
            ]);
    }

    public function test_product_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/products/999');
        $response->assertNotFound();
    }

    public function test_available_stock_excludes_active_holds(): void
    {
        $product = Product::factory()->create([
            'stock_total' => 100,
            'stock_sold' => 10,
        ]);

        // Create active holds
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 20,
        ]);

        $response = $this->getJson("/api/products/{$product->id}");
        
        // Cache should reflect reserved stock
        $response->assertOk();
        $this->assertEquals(70, $response->json('available_stock'));
    }
}

