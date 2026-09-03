<?php

namespace App\Models;

use App\Enums\SiteReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'author_name',
        'rating',
        'text',
        'status',
        'ip_hash',
    ];

    /** Never serialize visitor IP hashes into JSON/Livewire payloads. */
    protected $hidden = [
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => SiteReviewStatus::class,
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', SiteReviewStatus::Approved->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SiteReviewStatus::Pending->value);
    }
}
