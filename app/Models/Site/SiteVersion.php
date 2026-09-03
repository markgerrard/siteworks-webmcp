<?php

namespace App\Models\Site;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVersion extends Model
{
    public $timestamps = false;

    protected $table = 'site_versions';

    protected $fillable = [
        'site_id', 'version', 'composition', 'page_revisions',
        'published_at', 'published_by_user_id', 'publish_note',
        'actor_channel',
    ];

    protected $casts = [
        'composition' => 'array',
        'page_revisions' => 'array',
        'published_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
