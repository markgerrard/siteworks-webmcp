<?php

namespace App\Models\Shop;

use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Model;

class ProductVariantImage extends Model
{
    protected $table = 'shop_product_variant_images';

    protected $fillable = ['variant_id', 'path', 'sort_order', 'alt'];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function url(): string
    {
        return MediaStorage::disk()->url($this->path);
    }
}
