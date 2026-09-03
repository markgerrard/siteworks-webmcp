<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $table = 'shop_stock_reservations';

    protected $fillable = ['variant_id', 'cart_id', 'order_id', 'qty', 'expires_at', 'released_at', 'committed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'committed_at' => 'datetime',
    ];

    /**
     * Reservations that are still withholding stock.
     *
     * expires_at is part of this, and used to be ignored: it was written on every
     * reservation and read by nothing, so an abandoned cart held its stock forever.
     * The only expiry job walks ORDERS, so it can never reach a cart-only reservation.
     *
     * An order-attached reservation is exempt from the cart TTL — orders have their
     * own lifecycle (ExpirePendingOrders) and a paid order's stock is committed.
     */
    public function scopeActive($q)
    {
        return $q->whereNull('released_at')
            ->whereNull('committed_at')
            ->where(function ($q) {
                $q->whereNotNull('order_id')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForVariant($q, int $variantId)
    {
        return $q->where('variant_id', $variantId);
    }
}
