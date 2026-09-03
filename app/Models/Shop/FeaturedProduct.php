<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class FeaturedProduct extends Model
{
    protected $table = 'shop_featured_products';

    protected $fillable = ['site_id', 'product_id', 'sort_order', 'starts_at', 'ends_at'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeActive($q)
    {
        return $q
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeForSite($q, int $siteId)
    {
        return $q->where('site_id', $siteId);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
