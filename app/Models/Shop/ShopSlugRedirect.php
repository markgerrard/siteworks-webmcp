<?php

namespace App\Models\Shop;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSlugRedirect extends Model
{
    use HasFactory;

    protected $table = 'shop_slug_redirects';

    protected $fillable = [
        'site_id',
        'kind',
        'old_slug',
        'slug',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
