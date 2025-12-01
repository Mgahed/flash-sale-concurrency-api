<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('qty')->unsigned();
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->boolean('released')->default(false);
            $table->timestamp('created_at');
            
            // Indexes for performance and expiry queries
            $table->index(['product_id', 'expires_at']);
            $table->index(['expires_at', 'used', 'released']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};

