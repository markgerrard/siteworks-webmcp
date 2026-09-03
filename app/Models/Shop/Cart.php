<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'shop_carts';

    protected $fillable = ['site_id', 'session_cookie_id', 'customer_id', 'email', 'last_active_at', 'abandoned_at', 'converted_order_id'];

    protected $casts = [
        'last_active_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
