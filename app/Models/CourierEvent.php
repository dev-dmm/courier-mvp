<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierEvent extends Model
{
    protected $fillable = [
        'voucher_id',
        'courier_name',
        'event_code',
        'event_description',
        'location',
        'event_time',
        'raw_payload',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
