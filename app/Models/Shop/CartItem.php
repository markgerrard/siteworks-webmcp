<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $table = 'shop_cart_items';

    protected $fillable = [
        'cart_id', 'variant_id', 'qty', 'unit_price_cents', 'reservation_id',
        'personalisation', 'personalisation_hash',
    ];

    protected $attributes = [
        'personalisation_hash' => '',
    ];

    protected $casts = [
        'personalisation' => 'array',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
