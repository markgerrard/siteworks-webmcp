<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One before/after image pair on a "Transformation Stories" section.
 *
 * Generation contract: `before_image` must exist before `after_image`
 * is created — the after is produced from the before bytes as the
 * reference, which is what gives the pair
 * its scene continuity. The narrative + prompts are persisted so a
 * regen of the pair can reuse the same generated output without an
 * extra LLM round-trip.
 *
 * Pairs are AI-generated and gated server-side by the site's
 * honest_project_framing flag. When that flag is on, neither the
 * jobs nor the render layer should produce / show pairs.
 */
class BeforeAfterPair extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id',
        'page_id',
        'sort_order',
        'narrative',
        'before_image_id',
        'after_image_id',
        'before_prompt',
        'after_transformation_prompt',
        'status',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(GeneratedPage::class, 'page_id');
    }

    public function beforeImage(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'before_image_id');
    }

    public function afterImage(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'after_image_id');
    }
}
