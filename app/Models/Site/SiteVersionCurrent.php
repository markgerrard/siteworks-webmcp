<?php

namespace App\Models\Site;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVersionCurrent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'site_versions_current';

    protected $primaryKey = 'site_id';

    protected $fillable = ['site_id', 'version_id', 'updated_at'];

    protected $casts = ['updated_at' => 'datetime'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SiteVersion::class, 'version_id');
    }
}
