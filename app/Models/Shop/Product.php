<?php

namespace App\Models\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $table = 'shop_products';

    protected $fillable = [
        'site_id', 'slug', 'name', 'description', 'facts', 'tax_class_id',
        'status', 'published_at', 'price_from', 'tags', 'primary_image_id', 'revision',
        'is_ai_seeded', 'is_ai_reviewed', 'ai_seed_source',
        'ai_prompt_used', 'ai_model_version', 'ai_seeded_at', 'archived_at',
        'customer_inputs', 'review_notes',
    ];

    protected $attributes = [
        'price_from' => false,
        'revision' => 0,
    ];

    protected $casts = [
        'status' => ProductStatus::class,
        'published_at' => 'datetime',
        'facts' => 'array',
        'price_from' => 'boolean',
        'tags' => 'array',
        'revision' => 'integer',
        'is_ai_seeded' => 'boolean',
        'is_ai_reviewed' => 'boolean',
        'ai_seeded_at' => 'datetime',
        'archived_at' => 'datetime',
        'customer_inputs' => 'array',
        'review_notes' => 'array',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'shop_product_category')
            ->withPivot('is_primary');
    }

    public function primaryCategory()
    {
        return $this->categories()->wherePivot('is_primary', true);
    }

    public function taxClass()
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }
}
