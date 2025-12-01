<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a flash sale product with limited stock
        Product::create([
            'name' => 'Flash Sale - Limited Edition Widget',
            'price' => 99.99,
            'stock_total' => 100,
            'stock_sold' => 0,
        ]);

        // Create another product for testing
        Product::create([
            'name' => 'Premium Gadget',
            'price' => 149.99,
            'stock_total' => 50,
            'stock_sold' => 0,
        ]);
    }
}

