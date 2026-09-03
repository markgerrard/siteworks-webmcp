<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMagicLink extends Model
{
    protected $table = 'shop_customer_magic_links';

    protected $fillable = [
        'customer_id', 'token_hash', 'requested_ip', 'expires_at', 'consumed_at', 'consumed_ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
