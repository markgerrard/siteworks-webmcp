<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    protected $table = 'shop_shipping_rates';

    protected $fillable = [
        'site_id',
        'strategy',
        'flat_amount_cents',
        'free_threshold_cents',
        'method_label',
        'tiers',
        'default_weight_grams',
    ];

    protected $attributes = [
        'default_weight_grams' => 500,
    ];

    protected $casts = [
        'flat_amount_cents' => 'integer',
        'free_threshold_cents' => 'integer',
        'tiers' => 'array',
        'default_weight_grams' => 'integer',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
