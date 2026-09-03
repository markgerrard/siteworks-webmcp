<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalApiCall extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id', 'provider', 'endpoint', 'http_status',
        'request', 'response_meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response_meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
