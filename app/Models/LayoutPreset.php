<?php

namespace App\Models;

use App\Services\Site\PageLayoutRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LayoutPreset extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'page_kind' => 'service',
    ];

    protected $fillable = [
        'site_id',
        'page_kind',
        'key',
        'label',
        'description',
        'recipe',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipe' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $preset): void {
            app(PageLayoutRegistry::class)->invalidateFor($preset);
        });

        static::deleted(function (self $preset): void {
            app(PageLayoutRegistry::class)->invalidateFor($preset);
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
