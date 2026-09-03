<?php

namespace App\Models;

use App\Enums\HeroVersionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HeroVersion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id', 'page_type', 'slot', 'url', 'watermark_url',
        'prompt', 'model', 'placement', 'is_active', 'upgrade_candidate',
        'source',
    ];

    protected $casts = [
        'placement' => 'array',
        'is_active' => 'boolean',
        'upgrade_candidate' => 'boolean',
        'source' => HeroVersionSource::class,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Scope to the wide hero banner slot.
     */
    public function scopeHeroSlot(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('slot', 'hero');
    }

    /**
     * Scope to the secondary intro / detail-shot slot.
     */
    public function scopeIntroSlot(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('slot', 'intro');
    }

    /**
     * Scope to the showcase-family band / portrait slot.
     */
    public function scopeBandSlot(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('slot', 'band');
    }

    /**
     * Belt-and-braces alongside the partial-unique-index fix in
     * 2026_05_08_020909: clear is_active before soft-delete so
     * soft-deleting an active version never strands the unique slot,
     * even on legacy databases that haven't run the migration yet.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $version): void {
            if (! $version->isForceDeleting() && $version->is_active) {
                $version->forceFill(['is_active' => false])->saveQuietly();
            }
        });
    }
}
