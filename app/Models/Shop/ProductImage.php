<?php

namespace App\Models\Shop;

use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $table = 'shop_product_images';

    protected $fillable = ['product_id', 'path', 'sort_order', 'alt'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function url(string $variant = 'card'): string
    {
        return MediaStorage::disk()->url($this->path);
    }
}
