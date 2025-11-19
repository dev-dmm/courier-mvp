<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerStat extends Model
{
    protected $fillable = [
        'customer_id',
        'customer_hash',
        'total_orders',
        'late_deliveries',
        'returns',
        'first_order_at',
        'last_order_at',
        'delivery_risk_score',
        'meta',
    ];

    protected $casts = [
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
