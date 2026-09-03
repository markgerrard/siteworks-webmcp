<?php

namespace App\Models\Shop;

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class ShopSnapshot extends Model
{
    protected $table = 'shop_snapshots';

    protected $fillable = [
        'site_id', 'version', 'json', 'status',
        'size_bytes', 'build_duration_ms', 'product_count',
        'built_at', 'built_by_job_id', 'build_error',
        'hero_image_url', 'hero_alt', 'hero_height', 'bg_position_y', 'text_zone', 'hero_width', 'hero_enabled', 'hero_headline', 'hero_text_style', 'hero_accent_word', 'shared_category_hero', 'hero_prompt', 'hero_model',
    ];

    protected $attributes = [
        'hero_width' => 'boxed',
        'hero_enabled' => true,
    ];

    protected $casts = [
        'json' => 'array',
        'status' => ShopSnapshotStatus::class,
        'built_at' => 'datetime',
        'hero_enabled' => 'boolean',
        'shared_category_hero' => 'array',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
