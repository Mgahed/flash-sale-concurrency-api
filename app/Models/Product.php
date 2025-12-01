<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'price',
        'stock_total',
        'stock_sold',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_total' => 'integer',
        'stock_sold' => 'integer',
    ];

    /**
     * Get all holds for this product.
     */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    /**
     * Calculate available stock.
     * available_stock = stock_total - stock_sold - active_holds - pending_payment_holds
     *
     * Active holds include:
     * 1. Unused, unreleased holds (not expired)
     * 2. Used holds with pending payment orders (to prevent overselling during webhook delay)
     */
    public function calculateAvailableStock(): int
    {
        // Count unused holds (traditional reservations)
        $unusedHolds = $this->holds()
            ->where('used', false)
            ->where('released', false)
            ->where('expires_at', '>', now())
            ->sum('qty');

        // Count used holds that have pending payment orders (webhook not yet processed)
        // These must still reserve stock until payment is confirmed or cancelled
        $pendingPaymentHolds = $this->holds()
            ->where('used', true)
            ->where('released', false)
            ->whereHas('order', function ($query) {
                $query->where('status', 'pending_payment');
            })
            ->sum('qty');

        $totalReserved = $unusedHolds + $pendingPaymentHolds;

        return max(0, $this->stock_total - $this->stock_sold - $totalReserved);
    }
}

