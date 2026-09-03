<?php

namespace App\Models\Shop;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopDraft extends Model
{
    protected $table = 'shop_drafts';

    protected $primaryKey = 'site_id';

    public $incrementing = false;

    protected $fillable = [
        'site_id',
        'catalogue_revision',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'catalogue_revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'catalogue_revision' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
