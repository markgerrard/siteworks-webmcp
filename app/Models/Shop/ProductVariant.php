<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    use HasFactory;

    protected $table = 'shop_product_variants';

    protected $fillable = ['product_id', 'sku', 'label', 'price_cents', 'weight_grams'];

    protected $casts = [
        'price_cents' => 'integer',
        'weight_grams' => 'integer',
    ];

    /**
     * Label shown to shoppers on cart lines. Auto-created single variants
     * and the draft-product placeholder "Default" are suppressed.
     */
    public function shopperFacingLabel(): string
    {
        $label = trim((string) $this->label);
        if ($label === '' || Str::lower($label) === 'default') {
            return '';
        }

        $this->loadMissing('product.variants');

        return $this->product->variants->count() === 1 ? '' : $label;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function images()
    {
        return $this->hasMany(ProductVariantImage::class, 'variant_id')->orderBy('sort_order');
    }

    public function stock(): HasOne
    {
        return $this->hasOne(VariantStock::class, 'variant_id');
    }
}
