<?php

namespace App\Models;

use App\Support\Media\MediaKind;
use App\Support\Media\MediaOrigin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteMedia extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id',
        'project_item_id',
        'source',
        'actor_channel',
        'source_ref',
        'alt_text',
        'url',
        's3_key',
        'mime_type',
        'metadata',
        'kind',
        'origin',
        'width',
        'height',
        'title',
        'tags',
        'prompt',
        'provisional',
    ];

    protected $attributes = [
        // kind/origin are derived in booted()::creating from mime/source (DB defaults cover reads).
        'provisional' => false,
        'tags' => '[]',
    ];

    protected static function booted(): void
    {
        // Every writer (imports, portrait/editor ingest, generation jobs) gets kind/origin derived
        // — the library filters degrade otherwise.
        static::creating(function (SiteMedia $media): void {
            if (empty($media->getAttribute('kind'))) {
                $media->setAttribute('kind', \App\Support\Media\MediaKind::fromMime($media->mime_type));
            }
            if (empty($media->getAttribute('origin'))) {
                $media->setAttribute('origin', \App\Support\Media\MediaOrigin::fromSource((string) $media->source));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'tags' => 'array',
            'kind' => MediaKind::class,
            'origin' => MediaOrigin::class,
            'provisional' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function projectItem(): BelongsTo
    {
        return $this->belongsTo(ProjectItem::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(SiteMediaUsage::class);
    }

    public function scopeLibrary(Builder $query): Builder
    {
        return $query->where('provisional', false);
    }

    public function isDecorative(): bool
    {
        return $this->alt_text === '';
    }
}
