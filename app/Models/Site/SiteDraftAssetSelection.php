<?php

namespace App\Models\Site;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDraftAssetSelection extends Model
{
    protected $table = 'site_draft_asset_selections';

    protected $fillable = [
        'site_id',
        'family',
        'page_type',
        'slot',
        'version_id',
        'mode',
        'placement',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'version_id' => 'integer',
            'mode' => 'string',
            'placement' => 'array',
            'created_by_user_id' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
