<?php

namespace App\Models;

use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'site_id',
        'page_id',
        'detail_page_id',
        'type',
        'sort_order',
        'status',
        'source',
        'category',
        'category_id',
        'title',
        'description',
        'image_id',
        'metrics',
        'metadata',
        'content_hash',
        'media_hash',
        'published_snapshot',
        'image_job_state',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProjectItemType::class,
            'status' => ProjectItemStatus::class,
            'source' => ProjectItemSource::class,
            'metrics' => 'array',
            'metadata' => 'array',
            'published_snapshot' => 'array',
        ];
    }

    /**
     * True when the item has unpublished content edits — current
     * field values differ from the last-published snapshot. Always
     * false when no snapshot exists (item has never been published).
     */
    public function hasUnpublishedDrift(): bool
    {
        $snap = $this->published_snapshot;
        if (! is_array($snap)) {
            return false;
        }

        return ($snap['title'] ?? null) !== $this->title
            || ($snap['description'] ?? null) !== $this->description
            || ($snap['category'] ?? null) !== $this->category
            || ($snap['metrics'] ?? null) !== $this->metrics
            || ($snap['image_id'] ?? null) !== $this->image_id
            || ($snap['sort_order'] ?? null) !== $this->sort_order;
    }

    /**
     * Snapshot of currently-published values for the publish flow.
     * sort_order is included so the banner picks up drag-drop reorder
     * as drift, and Discard reverts the order along with content.
     *
     * @return array<string, mixed>
     */
    public function buildPublishSnapshot(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'metrics' => $this->metrics,
            'image_id' => $this->image_id,
            'sort_order' => $this->sort_order,
        ];
    }

    /**
     * Revert content fields to last-published values. No-op when no
     * snapshot exists. Used by SitePublishService::discardAllDrafts.
     */
    public function revertFromPublishSnapshot(): void
    {
        $snap = $this->published_snapshot;
        if (! is_array($snap)) {
            return;
        }

        $this->update([
            'title' => $snap['title'] ?? $this->title,
            'description' => $snap['description'] ?? $this->description,
            'category' => $snap['category'] ?? $this->category,
            'metrics' => $snap['metrics'] ?? $this->metrics,
            'image_id' => $snap['image_id'] ?? $this->image_id,
            'sort_order' => $snap['sort_order'] ?? $this->sort_order,
        ]);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(GeneratedPage::class, 'page_id');
    }

    public function detailPage(): BelongsTo
    {
        return $this->belongsTo(GeneratedPage::class, 'detail_page_id');
    }

    public function projectCategory(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'category_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'image_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SiteMedia::class, 'project_item_id')->latest();
    }

    /**
     * Supplementary case-study images (the multi-image grid below the
     * narrative on the case-studies layout). Filtered to rows tagged
     * with metadata.role = 'case_study_gallery' so legacy SiteMedia
     * rows (stacked regenerations of the same hero) don't accidentally
     * render as extras. Ordered by gallery_index so the prompts'
     * intended sequence is preserved.
     */
    public function galleryImages(): HasMany
    {
        return $this->hasMany(SiteMedia::class, 'project_item_id')
            ->where('metadata->role', 'case_study_gallery')
            ->orderBy('metadata->gallery_index');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', ProjectItemStatus::Archived->value);
    }

    public function scopeGallery(Builder $query): Builder
    {
        return $query->where('type', ProjectItemType::Gallery->value);
    }

    public function scopeCaseStudy(Builder $query): Builder
    {
        return $query->where('type', ProjectItemType::CaseStudy->value);
    }
}
