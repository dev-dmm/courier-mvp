<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shop extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'api_secret',
    ];

    protected static function booted()
    {
        static::creating(function (Shop $shop) {
            if (empty($shop->api_key)) {
                $shop->api_key = 'shop_' . Str::random(32);
            }

            if (empty($shop->api_secret)) {
                $shop->api_secret = Str::random(64);
            }

            // Optional: auto-generate slug from name if not provided
            if (empty($shop->slug) && !empty($shop->name)) {
                $base = Str::slug($shop->name);
                $slug = $base;
                $i = 1;

                while (static::where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $i++;
                }

                $shop->slug = $slug;
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shop_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
