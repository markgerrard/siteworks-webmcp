<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SiteMediaUsage extends Model
{
    protected $fillable = [
        'site_media_id',
        'usable_type',
        'usable_id',
        'slot',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'site_media_id');
    }

    public function usable(): MorphTo
    {
        return $this->morphTo();
    }
}
