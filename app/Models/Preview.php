<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Preview extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id', 'slug', 'theme', 'snapshot',
        'is_active', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
}
