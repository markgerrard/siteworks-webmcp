<?php

namespace App\Models;

use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Models\Site\PageRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeneratedPage extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Page slugs that must not be used as page_type values because they conflict
     * with dedicated route groups (shop, news) or reserved application paths.
     *
     * @var array<int, string>
     */
    public const RESERVED_SLUGS = ['shop', 'news', 'admin', 'login', 'register', '_edit'];

    protected $fillable = [
        'site_id', 'parent_id', 'page_type', 'kind', 'layout_preset_key', 'origin', 'nav_label', 'footer_label', 'sort_order',
        'content_data', 'version', 'model_used', 'status', 'hero_source',
        'draft_revision_id', 'published_revision_id', 'archived_at',
        'personalise_override',
    ];

    protected $attributes = [
        // Backstops the DB default so newly-constructed (non-persisted)
        // instances always have a status — tests and the page-manager
        // UI can rely on the cast without hitting a null enum.
        'status' => 'published',
        'hero_source' => 'shared',
        'origin' => 'pipeline',
    ];

    protected function casts(): array
    {
        return [
            'content_data' => 'array',
            'archived_at' => 'datetime',
            'status' => PageStatus::class,
            'kind' => PageKind::class,
            'origin' => PageOrigin::class,
        ];
    }

    /** Scope: pages eligible for the next publish + public visibility. */
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', PageStatus::Published->value);
    }

    /** Scope: pages intentionally hidden (visible in admin, not in nav/public). */
    public function scopeDraft(Builder $q): Builder
    {
        return $q->where('status', PageStatus::Draft->value);
    }

    /** Scope: retired pages (hidden in admin main list by default). */
    public function scopeArchived(Builder $q): Builder
    {
        return $q->where('status', PageStatus::Archived->value);
    }

    /** Scope: the set that should ever appear in a site's nav (Published only). */
    public function scopeVisibleInNav(Builder $q): Builder
    {
        return $q->where('status', PageStatus::Published->value);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function draftRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'draft_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'published_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class, 'page_id')->orderByDesc('created_at');
    }

    public function ownedProjectItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class, 'page_id');
    }

    public function detailProjectItem(): HasOne
    {
        return $this->hasOne(ProjectItem::class, 'detail_page_id');
    }

    /**
     * "Core" = non-service structural page that the site has at most one of
     * and that lives at the top level of the nav, never inside a Services
     * group. The original three (home/about/contact) are joined here by
     * `projects` because:
     *
     *  - The projects page has its own renderer (`projects_hero` + gallery),
     *    its own AI job (GenerateProjectsPageJob), and its own admin tab —
     *    it's structurally distinct from a service page.
     *  - df3cbe0 placed "Our Work" before Contact in the nav specifically
     *    so it stays at the top level, but OrganiseNavJob's
     *    `! isCorePage()` filter would otherwise drag it back INTO the
     *    Services group on every re-organise.
     *
     * Future structural pages (privacy, terms, news, shop) would belong
     * here too if they enter the page set.
     */
    public function isCorePage(): bool
    {
        if ($this->kind !== null) {
            return $this->kind === PageKind::Core;
        }

        return in_array($this->page_type, ['home', 'about', 'contact', 'projects'], true);
    }

    /**
     * When kind is set this is true only for PageKind::Service — not the
     * complement of isCorePage(). Editorial, guide, cost_guide, case_study,
     * hub, and project_detail are neither core nor service.
     *
     * When kind is null, falls back to ! isCorePage() (legacy string list).
     */
    public function isServicePage(): bool
    {
        if ($this->kind !== null) {
            return $this->kind === PageKind::Service;
        }

        return ! $this->isCorePage();
    }

    /**
     * Backfill generated_pages.kind from page_type for rows that still have
     * a NULL kind. Idempotent: already-set kinds are left alone.
     *
     * Rule (spec §2): home/about/contact/projects/privacy/terms → core;
     * article → editorial; everything else → service. Kept for tests and
     * ops reuse; the kind/origin migration inlines its own UPDATEs and
     * does not call this.
     */
    public static function backfillKindFromPageType(): int
    {
        $table = (new static)->getTable();

        $core = DB::table($table)
            ->whereNull('kind')
            ->whereIn('page_type', ['home', 'about', 'contact', 'projects', 'privacy', 'terms'])
            ->update(['kind' => PageKind::Core->value]);

        $editorial = DB::table($table)
            ->whereNull('kind')
            ->where('page_type', 'article')
            ->update(['kind' => PageKind::Editorial->value]);

        $service = DB::table($table)
            ->whereNull('kind')
            ->update(['kind' => PageKind::Service->value]);

        return $core + $editorial + $service;
    }

    /**
     * Slug is taken when a published or draft page already uses it, or an
     * archived page still pinned by the current SiteVersion does.
     * Archived-but-unpinned slugs are free (spec §6.2).
     */
    public static function slugIsTaken(Site $site, string $slug, ?self $parent = null): bool
    {
        $pageType = $parent === null || Str::startsWith($slug, $parent->page_type.'/')
            ? $slug
            : $parent->page_type.'/'.$slug;

        $pages = static::query()
            ->where('site_id', $site->id)
            ->where('page_type', $pageType)
            ->when(
                $parent !== null,
                fn (Builder $query) => $query->where('parent_id', $parent->id),
                fn (Builder $query) => Str::contains($pageType, '/') ? $query : $query->whereNull('parent_id'),
            )
            ->get(['id', 'status']);

        if ($pages->isEmpty()) {
            return false;
        }

        $pinnedIds = collect(
            Site\SiteVersionCurrent::query()
                ->with('version')
                ->where('site_id', $site->id)
                ->first()
                ?->version
                ?->page_revisions ?? []
        )->pluck('page_id')->map(fn ($id): int => (int) $id);

        foreach ($pages as $page) {
            if (in_array($page->status, [PageStatus::Published, PageStatus::Draft], true)) {
                return true;
            }

            if ($page->status === PageStatus::Archived && $pinnedIds->contains((int) $page->id)) {
                return true;
            }
        }

        return false;
    }

    public function displayName(): string
    {
        return ucwords(str_replace('-', ' ', $this->page_type));
    }

    public function publicPath(): string
    {
        return (string) $this->page_type;
    }

    /**
     * Fallback nav label when nav_label is unset. Used by OrganiseNavJob to
     * supply a stable candidate label for pages with no admin-chosen
     * override.
     */
    public function defaultNavLabel(): string
    {
        return $this->displayName();
    }
}
