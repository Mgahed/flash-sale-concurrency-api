<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Hold extends Model
{
    const UPDATED_AT = null; // No updated_at column

    protected $fillable = [
        'product_id',
        'qty',
        'expires_at',
        'used',
        'released',
    ];

    protected $casts = [
        'qty' => 'integer',
        'expires_at' => 'datetime',
        'used' => 'boolean',
        'released' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Get the product this hold belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the order associated with this hold.
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Check if this hold is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if this hold is valid for creating an order.
     */
    public function isValid(): bool
    {
        return !$this->used 
            && !$this->released 
            && !$this->isExpired();
    }
}

