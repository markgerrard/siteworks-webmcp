<?php

namespace App\Models\Shop;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopHeroVersion extends Model
{
    public $timestamps = false;

    protected $table = 'shop_hero_versions';

    protected $fillable = [
        'site_id',
        'scope',
        'scope_id',
        'image_url',
        'hero_alt',
        'model_used',
        'created_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'scope_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
