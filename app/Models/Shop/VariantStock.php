<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Model;

class VariantStock extends Model
{
    public $incrementing = false;

    protected $table = 'shop_variant_stock';

    protected $primaryKey = 'variant_id';

    public $timestamps = false;

    protected $fillable = ['variant_id', 'on_hand', 'updated_at'];

    protected $casts = ['updated_at' => 'datetime'];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
