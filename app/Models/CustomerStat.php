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
        'successful_deliveries',
        'failed_deliveries',
        'late_deliveries',
        'returns',
        'cod_orders',
        'cod_refusals',
        'first_order_at',
        'last_order_at',
        'delivery_success_rate',
        'delivery_risk_score',
        'meta',
    ];

    protected $casts = [
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
        'delivery_success_rate' => 'decimal:2',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
