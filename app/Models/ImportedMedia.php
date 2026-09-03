<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportedMedia extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id',
        'source',
        'external_id',
        'url',
        'width',
        'height',
        'caption',
        'imported_at',
        'assigned_to',
        'assigned_page_id',
        'placement',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'placement' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function assignedPage(): BelongsTo
    {
        return $this->belongsTo(GeneratedPage::class, 'assigned_page_id');
    }
}
