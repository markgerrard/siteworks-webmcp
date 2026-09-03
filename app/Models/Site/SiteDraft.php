<?php

namespace App\Models\Site;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteDraft extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'site_drafts';

    protected $fillable = ['site_id', 'composition', 'updated_by_user_id', 'updated_at', 'admin_revision'];

    protected $casts = [
        'composition' => 'array',
        'updated_at' => 'datetime',
        'admin_revision' => 'integer',
    ];

    protected $attributes = [
        'admin_revision' => 0,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
