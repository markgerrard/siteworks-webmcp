<?php

namespace App\Models\Shop;

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\RefundStatus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'shop_orders';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => OrderStatus::class,
        'refund_status' => RefundStatus::class,
        'shipping_address_json' => 'array',
        'placed_at' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
