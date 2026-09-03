<?php

namespace App\Models;

use App\Enums\LogoConceptSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class LogoConcept extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id',
        'source',
        'version',
        'path',
        'rank',
        'score',
        'is_selected',
        'metadata',
        'prompt',
        'quality',
    ];

    protected function casts(): array
    {
        return [
            'source' => LogoConceptSource::class,
            'metadata' => 'array',
            'is_selected' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function url(): string
    {
        return Storage::disk('s3')->url($this->path);
    }

    /**
     * Belt-and-braces alongside the partial-unique-index fix in
     * 2026_05_08_020404: clear is_selected before soft-delete so
     * deleting the selected concept never strands the unique slot,
     * even on legacy databases that haven't run the migration yet.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $concept): void {
            // Force-delete sweeps the row entirely, no constraint risk.
            // Soft-delete keeps the row but the partial index now
            // excludes deleted_at IS NOT NULL — but flipping
            // is_selected back to false here means the index never
            // sees a "selected, deleted" state in the gap between the
            // soft-delete write and any concurrent reader, which keeps
            // behaviour identical on legacy databases.
            if (! $concept->isForceDeleting() && $concept->is_selected) {
                $concept->forceFill(['is_selected' => false])->saveQuietly();
            }
        });
    }
}
