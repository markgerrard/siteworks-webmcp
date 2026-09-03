<?php

namespace App\Models\Site;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EditorAgentApproval extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'kind',
        'site_id',
        'requested_by_user_id',
        'requested_by_identifier',
        'approved_by_user_id',
        'approved_by_identifier',
        'channel',
        'grant_principal',
        'operation',
        'args_hash',
        'summary',
        'requested_at',
        'approved_at',
        'denied_at',
        'consumed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeLivePending(Builder $query): Builder
    {
        return $query
            ->whereNull('approved_at')
            ->whereNull('denied_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    public function scopeSpendable(Builder $query): Builder
    {
        return $query
            ->whereNotNull('approved_at')
            ->whereNull('denied_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
