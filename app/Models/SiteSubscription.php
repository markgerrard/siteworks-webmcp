<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteSubscription extends Model
{
    use HasFactory;

    public const PRODUCT_MANAGED_CONTENT = 'managed_content';

    /**
     * @var list<string>
     */
    public const DEFAULT_KINDS = [
        'service',
        'guide',
        'cost_guide',
        'case_study',
        'hub',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'product' => self::PRODUCT_MANAGED_CONTENT,
        'monthly_page_quota' => 3,
        'kinds' => '["service","guide","cost_guide","case_study","hub"]',
        'active' => true,
        'carryover_credit' => 0,
    ];

    protected $fillable = [
        'site_id',
        'product',
        'monthly_page_quota',
        'kinds',
        'active',
        'started_at',
        'carryover_credit',
    ];

    protected function casts(): array
    {
        return [
            'kinds' => 'array',
            'active' => 'boolean',
            'monthly_page_quota' => 'integer',
            'carryover_credit' => 'integer',
            'started_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
