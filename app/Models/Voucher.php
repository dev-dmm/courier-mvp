<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'shop_id',
        'order_id',
        'customer_id',
        'customer_hash',
        'voucher_number',
        'courier_name',
        'courier_service',
        'tracking_url',
        'status',
        'shipped_at',
        'delivered_at',
        'returned_at',
        'failed_at',
        'meta',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'failed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function courierEvents(): HasMany
    {
        return $this->hasMany(CourierEvent::class);
    }
}
