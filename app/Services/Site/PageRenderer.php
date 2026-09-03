<?php

namespace App\Services\Site;

use App\Enums\Archetype;
use App\Enums\LogoConceptSource;
use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Enums\ProjectsLayout;
use App\Http\Controllers\Shop\CartController;
use App\Models\BeforeAfterPair;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\ProjectItem;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\SiteMedia;
use App\Services\Shop\CartService;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Support\ProjectSectionVocabulary;
use App\Support\Shop\ShopNavMenu;
use App\Support\Textures\TextureResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PageRenderer
{
    /** @var array<string, LogoConcept|null> */
    private array $resolvedLogoConceptBySiteId = [];

    /** @var array<int, string|null> */
    private array $resolvedOverlayLogoUrlBySiteId = [];

    public function __construct(
        protected SectionSchema $schema,
        protected ThemeResolver $themeResolver,
        protected PageLayoutRegistry $pageLayoutRegistry,
        protected DraftAssetSelections $draftAssetSelections,
        protected CartService $cartService,
        protected HeroResolution $heroResolution,
    ) {}

    /**
     * Render a page in the given mode.
     *
     * Modes:
     *   - 'public': resolves via site_versions_current; only published pinned revisions; no markers
     *   - 'admin-preview': resolves via site_drafts; uses draft_revision_id ?? published_revision_id; no markers
     *   - 'admin-edit': same as admin-preview but emits data-editable markers
     *
     * $publicHostNav: when true, build same-host public paths ('/', '/{slug}')
     * for nav items even in admin-edit mode. Used when admin-edit is served
     * on the customer's public host (via edit_session cookie); nav clicks then
     * stay on the same host and the cookie keeps the browser in edit mode.
     *
     * Returns full HTML page string.
     */
    /**
     * Render a specific historical version of a page.
     * Reads pinned revisions from $version->page_revisions instead of site_versions_current.
     */
    public function renderVersion(Site $site, int $pageId, SiteVersion $version): string
    {
        $pinned = collect($version->page_revisions)->firstWhere('page_id', $pageId);
        if (! $pinned) {
            abort(404);
        }

        $page = GeneratedPage::find($pageId);
        if (! $page) {
            abort(404);
        }

        $pinnedIds = collect($version->page_revisions)->pluck('page_id')->filter()->all();
        $this->publicVersionCache = [
            'version' => $version,
            'pagesById' => $pinnedIds === []
                ? collect()
                : GeneratedPage::query()->whereIn('id', $pinnedIds)->get()->keyBy('id'),
        ];

        $revision = PageRevision::find($pinned['revision_id']);

        $resolution = [
            'page' => $page,
            'content' => $revision?->content_data ?? [],
            'composition' => $version->composition,
        ];

        return $this->renderResolution($site, $resolution, 'admin-preview', useDraftAssets: false);
    }

    public function render(Site $site, int $pageId, string $mode = 'public', bool $publicHostNav = false, bool $signedNav = false, ?string $parentOrigin = null, bool $formPanel = false, bool $useDraftAssets = false): string
    {
        $resolution = $this->resolve($site, $pageId, $mode);

        return $this->renderResolution($site, $resolution, $mode, $publicHostNav, signedNav: $signedNav, parentOrigin: $parentOrigin, formPanel: $formPanel, useDraftAssets: $useDraftAssets);
    }

    /**
     * One-page mode: concatenate every pinned page's sections into a single
     * stacked document, wrap each page-group in an anchor div, and rewrite
     * nav hrefs to in-page anchors (#home, #about, #contact).
     *
     * Falls back to normal single-page render if the site has no pinned pages.
     */
    public function renderStacked(Site $site, string $mode = 'public', bool $publicHostNav = false, bool $useDraftAssets = false): string
    {
        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        if (! $current) {
            abort(404);
        }
        $version = SiteVersion::find($current->version_id);
        if (! $version) {
            abort(404);
        }

        $homeId = $version->composition['homepage_page_id'] ?? null;
        abort_unless($homeId, 404);

        $homeResolution = $this->resolve($site, (int) $homeId, $mode);

        // Resolve every other pinned page in nav order, then splice their
        // sections into the home resolution's content, wrapping each group
        // in an anchor marker so the nav can scroll to them.
        $pinnedIds = collect($version->page_revisions)->pluck('page_id')->filter()->all();
        $allPages = GeneratedPage::whereIn('id', $pinnedIds)
            ->whereNull('parent_id')
            ->get()
            ->keyBy('id');

        $mergedSections = [];
        $homePageType = $homeResolution['page']->publicPath();
        $mergedSections[] = ['type' => '__anchor', 'slug' => $homePageType];
        foreach ($homeResolution['content']['sections'] ?? [] as $s) {
            $s['__page_type'] = $homePageType;
            $mergedSections[] = $s;
        }

        foreach ($pinnedIds as $id) {
            if ((int) $id === (int) $homeId) {
                continue;
            }
            $page = $allPages[$id] ?? null;
            if (! $page || $page->publicPath() === '') {
                continue;
            }
            $publicPath = $page->publicPath();
            $res = $this->resolvePublic($site, (int) $id);
            $mergedSections[] = ['type' => '__anchor', 'slug' => $publicPath];
            // Inject the service lead_form using this page's own GeneratedPage
            // (not the merged home page) so injectServiceLeadForm's "is this a
            // service page?" check fires correctly. renderResolution calls
            // injectServiceLeadForm on the merged resolution whose page is
            // always home, so service sections never received the form — #8.
            $pageSections = $this->injectServiceLeadForm(
                $site,
                $page,
                $res['content']['sections'] ?? [],
            );
            foreach ($pageSections as $s) {
                $s['__page_type'] = $publicPath;
                $mergedSections[] = $s;
            }
        }

        $resolution = [
            'page' => $homeResolution['page'],
            'content' => ['sections' => $mergedSections],
            'composition' => $homeResolution['composition'],
        ];

        return $this->renderResolution($site, $resolution, $mode, $publicHostNav, stackedNav: true, useDraftAssets: $useDraftAssets);
    }

    /**
     * Walk a resolved navItems tree and rewrite any href that targets a
     * pinned-page path to the corresponding in-page anchor. External links
     * and non-page entries pass through unchanged.
     */
    protected function rewriteNavToAnchors(array $navItems, array $pagesBySlug): array
    {
        // Invert pagesBySlug: normal href → anchor
        $hrefToAnchor = [];
        foreach ($pagesBySlug as $_slug => $anchor) {
            // pagesBySlug already holds '#slug' values in stacked mode; we
            // want a reverse map from any historic hrefs to the anchor. The
            // simpler heuristic: any href ending in /{$slug} or equal to '/'
            // for the home slug maps to the matching anchor.
        }

        return array_map(function ($item) use ($pagesBySlug) {
            if (! is_array($item)) {
                return $item;
            }

            // Rewrite leaf href by matching on page_type slug suffix.
            if (isset($item['href']) && is_string($item['href'])) {
                foreach ($pagesBySlug as $slug => $anchor) {
                    if ($item['href'] === $anchor) {
                        continue; // already an anchor
                    }
                    if ($item['href'] === '/' || str_ends_with(rtrim($item['href'], '/'), '/'.$slug)) {
                        $item['href'] = $anchor;
                        break;
                    }
                }
            }

            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->rewriteNavToAnchors($item['children'], $pagesBySlug);
            }

            return $item;
        }, $navItems);
    }

    /**
     * Build HTML from an already-resolved resolution array.
     *
     * @param  array{page: GeneratedPage, content: array, composition: array}  $resolution
     */
    protected function renderResolution(Site $site, array $resolution, string $mode = 'admin-preview', bool $publicHostNav = false, bool $stackedNav = false, bool $signedNav = false, ?string $parentOrigin = null, bool $formPanel = false, bool $useDraftAssets = false): string
    {
        $profile = $site->businessProfile?->profile_data ?? [];
        // composition.theme (when present) captures the admin's explicit
        // theme choice — beats palette extraction so admin edits actually
        // show on the live site after publish.
        $theme = $this->themeResolver->resolve(
            $site,
            $profile,
            $resolution['composition']['theme'] ?? null,
        );
        $renderTokens = $this->themeResolver->renderTokens($theme);
        $siteTexture = TextureResolver::resolve($site);

        // Resolve hero images. HeroVersion is our per-page-type source of truth
        // (one row per active hero with url, watermark_url, placement). The
        // Preview.snapshot.hero_images map can carry the same data, but it's
        // frozen at build time and loading the whole snapshot column just to
        // read the hero map is unnecessary work. So prefer HeroVersion; only
        // fall back to the snapshot read if HeroVersion has nothing (legacy
        // sites whose pipeline never wrote a HeroVersion row).
        $heroImages = [];
        $draftHeroSelections = collect();
        $draftHeroVersions = collect();
        if ($useDraftAssets) {
            $draftHeroSelections = $this->draftAssetSelections->all($site)
                ->where('family', 'hero')
                ->values();
            $draftHeroVersions = HeroVersion::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $draftHeroSelections->pluck('version_id'))
                ->get()
                ->keyBy('id');
        }

        // Resolve intro images (slot='intro'). Service pages with hero_source='shared'
        // don't get an intro row (shared hero has no per-page intro context), so those
        // fall back to null on the Blade side.
        $introImages = [];
        $activeIntroVersions = HeroVersion::where('site_id', $site->id)
            ->where('is_active', true)
            ->where('slot', 'intro')
            ->get(['page_type', 'url', 'watermark_url']);

        foreach ($activeIntroVersions as $iv) {
            if (is_string($iv->page_type) && $iv->page_type !== '') {
                $introImages[$iv->page_type] = [
                    'url' => $iv->url,
                    'watermark_url' => $iv->watermark_url,
                ];
            }
        }
        foreach ($draftHeroSelections->where('slot', 'intro') as $selection) {
            $version = $draftHeroVersions->get($selection->version_id);
            if ($version instanceof HeroVersion) {
                $introImages[$selection->page_type] = [
                    'url' => $version->url,
                    'watermark_url' => $version->watermark_url,
                ];
            }
        }

        // Resolve band images (slot='band'). Showcase-family sections
        // (features checklist, values statements, values ledger portrait)
        // prefer this dedicated slot and fall back to intro/hero in Blade
        // when no active band row exists for the page_type.
        // band, plus the picked band_2/band_3 slots consumed by the
        // editorial band_image_count option (picked images only — never
        // reused from hero/intro; the picker UI arrives with D6/D7).
        $bandImages = [];
        $bandImages2 = [];
        $bandImages3 = [];
        $activeBandVersions = HeroVersion::where('site_id', $site->id)
            ->where('is_active', true)
            ->whereIn('slot', ['band', 'band_2', 'band_3'])
            ->get(['page_type', 'slot', 'url', 'watermark_url']);

        foreach ($activeBandVersions as $bv) {
            if (is_string($bv->page_type) && $bv->page_type !== '') {
                $entry = [
                    'url' => $bv->url,
                    'watermark_url' => $bv->watermark_url,
                ];
                match ($bv->slot) {
                    'band' => $bandImages[$bv->page_type] = $entry,
                    'band_2' => $bandImages2[$bv->page_type] = $entry,
                    'band_3' => $bandImages3[$bv->page_type] = $entry,
                    default => null,
                };
            }
        }
        foreach ($draftHeroSelections->whereIn('slot', ['band', 'band_2', 'band_3']) as $selection) {
            $version = $draftHeroVersions->get($selection->version_id);
            if (! $version instanceof HeroVersion) {
                continue;
            }

            $entry = [
                'url' => $version->url,
                'watermark_url' => $version->watermark_url,
            ];
            match ($selection->slot) {
                'band' => $bandImages[$selection->page_type] = $entry,
                'band_2' => $bandImages2[$selection->page_type] = $entry,
                'band_3' => $bandImages3[$selection->page_type] = $entry,
                default => null,
            };
        }

        $heroStates = [];
        $pageHeroSources = GeneratedPage::where('site_id', $site->id)
            ->get(['id', 'page_type', 'hero_source', 'content_data', 'published_revision_id', 'draft_revision_id']);

        foreach ($pageHeroSources as $pageModel) {
            if (! is_string($pageModel->page_type) || $pageModel->page_type === '') {
                continue;
            }

            $state = $this->heroResolution->for(
                $site,
                $pageModel,
                $useDraftAssets,
                // The live flag alone is NOT the right third conjunct. resolveVideo() checks
                // home_hero_video_enabled only AFTER its draft-selection branch, so hoisting the flag
                // up here would also suppress drafted video — a site choosing its first video has the
                // flag false by the drafts law until publish, which would reopen a preview/publish
                // divergence on the primary admin-preview path.
                resolveVideo: $pageModel->getKey() === $resolution['page']->getKey()
                    && $pageModel->page_type === 'home'
                    && ($site->home_hero_video_enabled || $useDraftAssets),
            );
            $heroStates[$pageModel->page_type] = $state;
            $heroImages[$pageModel->page_type] = $state->image_url === null
                ? null
                : [
                    'url' => $state->image_url,
                    'watermark_url' => null,
                    'placement' => $state->placement,
                    'width' => $state->placement['image_width'] ?? $state->placement['width'] ?? null,
                    'height' => $state->placement['image_height'] ?? $state->placement['height'] ?? null,
                ];
        }

        $latestPreview = $site->previews()->latest()->first();

        // Legacy-site fallback: merge a known set of display flags from
        // Preview.snapshot into $profile if they aren't already present.
        // Admin-editor components write these flags to BusinessProfile,
        // but sites generated before the wire-through exist with the
        // values sitting in snapshot only. Without this fallback the
        // public site silently defaults while the admin UI displays the
        // old (correct) value.
        if ($latestPreview) {
            $snap = $latestPreview->snapshot ?? [];
            foreach (['hero_sizes', 'watermark_enabled', 'contact_form_enabled', 'top_bar_enabled', 'home_lead_form_enabled'] as $k) {
                if (! array_key_exists($k, $profile) && array_key_exists($k, $snap)) {
                    $profile[$k] = $snap[$k];
                }
            }
        }

        // Resolve logo url with same priority as PreviewRenderer.
        $logoUrl = $this->resolveLogoUrl($site, $useDraftAssets);

        // Home href for the logo link: derived from composition.homepage_page_id
        // so the brand link ALWAYS goes home regardless of nav ordering/groups.
        $homeHref = $this->resolveHomeHref($site, $resolution['composition'], $mode, $publicHostNav, $signedNav, $parentOrigin);

        // Map page_type (slug) → href for in-page cross-links (service cards,
        // CTAs pointing at pages by name, etc.). Honours the same mode/public-
        // host-nav rules as the nav itself.
        $pagesBySlug = $this->resolvePagesBySlug(
            $site,
            $resolution['composition'],
            $mode,
            $publicHostNav,
            $signedNav,
            $parentOrigin,
            rootPagesOnly: $stackedNav,
        );

        if ($stackedNav) {
            // In one-page mode, every nav entry becomes an in-page anchor.
            $stacked = [];
            foreach (array_keys($pagesBySlug) as $slug) {
                $stacked[$slug] = '#'.$slug;
            }
            $pagesBySlug = $stacked;
            $homeHref = '#'.$resolution['page']->publicPath();
        }

        $navItems = $this->resolveNavItems($site, $resolution['composition'], $mode, $publicHostNav, $signedNav, $parentOrigin);
        if ($stackedNav) {
            $navItems = $this->rewriteNavToAnchors($navItems, $pagesBySlug);
        }

        $pinnedPages = $this->needsPinnedPages($resolution['composition'], $resolution['content']['sections'] ?? [])
            ? $this->resolvePinnedPages(
                $site,
                $resolution['composition'],
                $mode,
                $publicHostNav,
                $signedNav,
                $parentOrigin,
            )
            : collect();

        $rawSections = $resolution['content']['sections'] ?? [];
        $sections = [];
        foreach ($rawSections as $i => $s) {
            if (is_array($s)) {
                $s['__stored_index'] = $s['__stored_index'] ?? $i;
            }
            $sections[] = $s;
        }

        // Splice the home's lead_form into service-page sections when the
        // site's lead_form_policy includes services. Render-time only — no
        // content mutation, no per-page regen cost. Inserted just before
        // the trailing `cta` section so the CTA pattern stays last.
        $sections = $this->injectServiceLeadForm(
            $site,
            $resolution['page'] ?? null,
            $sections,
        );

        // When a form is already on this page (lead_form on home / injected
        // service pages, or contact_form on contact), the trailing `cta`
        // section's "contact us" button is redundant — swap it to a phone
        // CTA so the two lead paths stay distinct.
        $sections = $this->swapCtaToPhoneWhenFormPresent($sections, $profile);

        // Render-time projects-page layout switch. When site.projects_layout =
        // case_studies, drop the tile grid and remap case_study_highlights to
        // the long-form narrative variant. Toggle is bidirectional and
        // non-destructive — both layouts read from the same project_items.
        $sections = $this->applyProjectsLayout($site, $resolution['page'] ?? null, $sections);

        // Render-time home-page layout preset. Stamps per-section `variant`
        // keys from config/site_home_layouts.php — explicit variants already
        // present in content_data always win. Non-destructive: nothing is
        // persisted, so toggling home_layout is instant and reversible.
        // admin-edit skips the portfolio_strip splice so editable indices stay
        // aligned with content_data.sections (PageFieldUpdateController offsets).
        $sections = $this->applyHomeLayout($site, $resolution['page'] ?? null, $sections, $mode);

        // Render-time service/about/projects layout presets. Same contract as
        // home_layout: render-time, non-destructive, explicit variants win.
        // Projects recipe keys resolve from sites.services_layout (see
        // PageLayoutRegistry::resolveProjectsRecipeKey); sites.projects_layout
        // remains the CaseStudies swap in applyProjectsLayout above.
        $page = $resolution['page'] ?? null;
        $sections = $this->applyPageKindLayout($site, $page, $sections, 'service');
        $sections = $this->applyPageKindLayout($site, $page, $sections, 'about', ['about']);
        $sections = $this->applyPageKindLayout($site, $page, $sections, 'projects', ['projects']);
        $sections = $this->applyPageKindLayout($site, $page, $sections, 'project_detail');

        // Honest-framing render gate for the AI-generated B/A pairs. The job
        // dispatch is also gated, but this is defence-in-depth: if pairs were
        // generated when the flag was off and the flag is later turned on,
        // the section disappears immediately without a regen. Persisted rows
        // stay intact — turning the flag back off restores the section.
        if ($site->effectiveHonestFraming()) {
            $sections = array_values(array_filter(
                $sections,
                fn ($s) => ($s['type'] ?? null) !== 'before_after'
            ));
        }

        // Batch-load any ProjectItem rows referenced across the page's
        // sections (project_gallery / case_study_highlights). One query,
        // keyed by id — avoids N+1 when a tile partial reads items by id.
        $referencedItemIds = [];
        foreach ($sections as $section) {
            $ids = $section['item_ids'] ?? [];
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $referencedItemIds[] = (int) $id;
                    }
                }
            }
        }
        $referencedItemIds = array_values(array_unique($referencedItemIds));
        // Scope by site_id so a stale or hand-edited item_id from another
        // site can never bleed across — section item_ids are content-data,
        // not FK-enforced at write time (same threat model as pair_ids below).
        // Public mode only hydrates Published items; admin modes keep draft
        // visibility for the projects gallery editor preview.
        // galleryImages eager-loads the case-study supplementary images.
        // Always loaded — for grid-layout
        // sites the relation is empty so the cost is one extra query.
        $itemsById = empty($referencedItemIds)
            ? collect()
            : ProjectItem::with(['image', 'galleryImages', 'detailPage'])
                ->where('site_id', $site->id)
                ->whereIn('id', $referencedItemIds)
                ->when(
                    $mode === 'public',
                    fn ($q) => $q->where('status', ProjectItemStatus::Published->value),
                    fn ($q) => $q->where('status', '!=', ProjectItemStatus::Archived->value),
                )
                ->get()
                ->keyBy('id');

        // Mirror itemsById for the before/after pairs. Eager-loads
        // both image FKs so the blade renders without an N+1 hop.
        $referencedPairIds = [];
        foreach ($sections as $section) {
            $ids = $section['pair_ids'] ?? [];
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $referencedPairIds[] = (int) $id;
                    }
                }
            }
        }
        $referencedPairIds = array_values(array_unique($referencedPairIds));
        // Scope by site_id so a stale or hand-edited pair_id from another
        // site can never bleed across — the section-level pair_ids list
        // is content-data, not enforced by FKs at write time.
        $pairsById = empty($referencedPairIds)
            ? collect()
            : BeforeAfterPair::with(['beforeImage', 'afterImage'])
                ->where('site_id', $site->id)
                ->whereIn('id', $referencedPairIds)
                ->get()
                ->keyBy('id');

        // Hydrate SiteMedia for section-level image_ids and entry lists (gallery,
        // team portraits with alternate_image_id, etc.).
        // Scope by site_id so a stale or hand-edited image_id from another
        // site can never bleed across — section image_ids are content-data,
        // not FK-enforced at write time (same threat model as pair_ids above).
        $mediaById = $this->hydrateMediaById($site, $sections);

        $projectVocab = ProjectSectionVocabulary::for($site);

        // Flag-gated service-page galleries: batch-load gallery items per
        // category referenced by service_gallery sections, newest first,
        // soft-capped per category. Keyed by category for the section
        // blade. Same site scoping + status/mode rules as itemsById.
        $serviceGalleryItems = collect();
        if (config('site.service_page_galleries_enabled')) {
            $galleryCategories = [];
            foreach ($sections as $section) {
                $category = $section['category'] ?? null;
                if (($section['type'] ?? null) === 'service_gallery' && is_string($category) && $category !== '') {
                    $galleryCategories[] = $category;
                }
            }
            $galleryCategories = array_values(array_unique($galleryCategories));
            if ($galleryCategories !== []) {
                $cap = max(1, (int) config('site.service_page_gallery_cap', 100));
                $serviceGalleryItems = ProjectItem::with('image')
                    ->where('site_id', $site->id)
                    ->where('type', ProjectItemType::Gallery->value)
                    ->whereIn('category', $galleryCategories)
                    ->when(
                        $mode === 'public',
                        fn ($q) => $q->where('status', ProjectItemStatus::Published->value),
                        fn ($q) => $q->where('status', '!=', ProjectItemStatus::Archived->value),
                    )
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy('category')
                    ->map(fn ($group) => $group->take($cap)->values());
            }
        }

        return view('site.page', [
            'site' => $site,
            'page' => $resolution['page'],
            'sections' => $sections,
            // SEO meta lives under `meta.seo` on translated (sections-array)
            // content, or at the top level on legacy flat-shape content
            // (service pages, unmigrated about/contact).
            'seoMeta' => $resolution['content']['meta']['seo']
                ?? $resolution['content']['seo']
                ?? [],
            'composition' => $resolution['composition'],
            'navItems' => $navItems,
            'pinnedPages' => $pinnedPages,
            'mode' => $mode,
            'schema' => $this->schema,
            'emitMarkers' => $mode === 'admin-edit',
            'emitFormMarkers' => $mode === 'admin-edit' && $formPanel,
            'theme' => $theme,
            'renderTokens' => $renderTokens,
            'siteTexture' => $siteTexture,
            'profile' => $profile,
            'heroImages' => $heroImages,
            'heroStates' => $heroStates,
            'introImages' => $introImages,
            'bandImages' => $bandImages,
            'bandImages2' => $bandImages2,
            'bandImages3' => $bandImages3,
            'logoUrl' => $logoUrl,
            'logoTransparent' => $this->resolveLogoIsTransparent($site, $useDraftAssets),
            'logoPlate' => $this->resolveLogoPlate($site, $useDraftAssets),
            'overlayLogoUrl' => $this->resolveOverlayLogoUrl($site),
            'homeHref' => $homeHref,
            'pagesBySlug' => $pagesBySlug,
            'itemsById' => $itemsById,
            'pairsById' => $pairsById,
            'serviceGalleryItems' => $serviceGalleryItems,
            'mediaById' => $mediaById,
            'projectVocab' => $projectVocab,
            'pageHasForm' => $this->pageHasFormSection($sections),
            'shopCartEnabled' => $site->hasPurchasableShop() && $site->shopUsesCartChrome(),
            // Account exists in every shop mode: enquire-mode customers sign in to see their enquiries (T3).
            'shopAccountEnabled' => $site->hasPurchasableShop(),
            'shopSearchEnabled' => $site->hasPurchasableShop(),
            // Public site HTML may be cached across visitors. Keep the badge
            // neutral here; shop responses below are uncached and use the
            // visitor's real cart quantity.
            'shopCartItemCount' => 0,
            'useDraftAssets' => $useDraftAssets,
        ])->render();
    }

    /**
     * Build the shared layout context (theme, nav, logo, profile, etc.) used
     * by both the site page template AND external surfaces that want to wear
     * the site's chrome — e.g. the shop layout. Always resolves against the
     * published site_versions_current (public mode) so shop renders inside
     * the live site's header/footer.
     *
     * @return array{theme: array, renderTokens: array<string, string>, navItems: array, logoUrl: ?string, profile: array, homeHref: string, composition: array, site: Site, shopCartEnabled: bool, shopCartItemCount: int, shopSearchEnabled: bool, shopAccountEnabled: bool}
     */
    public function layoutContext(Site $site): array
    {
        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        $composition = [];
        if ($current) {
            $version = SiteVersion::find($current->version_id);
            if ($version) {
                $composition = $version->composition ?? [];
            }
        }
        if (empty($composition)) {
            // No published version yet — fall back to draft composition so
            // shop still renders sensibly pre-first-publish.
            $draft = SiteDraft::where('site_id', $site->id)->first();
            $composition = $draft?->composition ?? [];
        }

        $profile = $site->businessProfile?->profile_data ?? [];
        $theme = $this->themeResolver->resolve($site, $profile, $composition['theme'] ?? null);
        $navItems = $this->resolveNavItems($site, $composition, 'public');
        $homeHref = $this->resolveHomeHref($site, $composition, 'public', false);
        $logoUrl = $this->resolveLogoUrl($site);
        $shopCartEnabled = $site->hasPurchasableShop() && $site->shopUsesCartChrome();
        $shopAccountEnabled = $site->hasPurchasableShop(); // every shop mode (enquiries live in the account too)
        $shopSearchEnabled = $site->hasPurchasableShop();
        $shopCartItemCount = $shopCartEnabled
            ? $this->cartService->itemCountForSession(
                $site->id,
                request()->cookie(CartController::COOKIE_NAME),
            )
            : 0;

        return [
            'site' => $site,
            'theme' => $theme,
            'renderTokens' => $this->themeResolver->renderTokens($theme),
            'navItems' => $navItems,
            'logoUrl' => $logoUrl,
            'profile' => $profile,
            'homeHref' => $homeHref,
            'composition' => $composition,
            'shopCartEnabled' => $shopCartEnabled,
            'shopCartItemCount' => $shopCartItemCount,
            'shopSearchEnabled' => $shopSearchEnabled,
            'shopAccountEnabled' => $shopAccountEnabled,
        ];
    }

    /**
     * Splice the home page's lead_form section into a service page's sections
     * when the site's lead_form_policy includes services. Render-time only,
     * so changing the policy (or editing the home's form copy) propagates to
     * every service page on the next request without any content regen.
     *
     * Placement: inserted just before the trailing `cta` section if one
     * exists, otherwise appended. The CTA pattern stays as the last section.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    /**
     * The render-time service-page injection decision, exposed so editor reads (get_page_structure)
     * list exactly what the page will show: null when nothing is injected, else the splice index
     * (null = append) and the [phone_cta_strip, lead_form] block. Single source of truth for
     * injectServiceLeadForm().
     *
     * @param  array<int, mixed>  $sections
     * @return array{index: int|null, block: list<array<string, mixed>>}|null
     */
    public function injectedServiceBlock(Site $site, ?GeneratedPage $page, array $sections, bool $useCache = true): ?array
    {
        if (! $page || ! $this->wouldInjectServiceLeadForm($site, $page)) {
            return null;
        }

        // Avoid double-injection if the service page content already has one.
        // Same check applies if the page has its own phone_cta_strip — pages
        // that have generated bespoke CTA copy (e.g. via
        // RegenerateProjectsCtaCopyJob on the projects archetype) supersede
        // the home-derived defaults.
        foreach ($sections as $s) {
            $type = $s['type'] ?? null;
            if ($type === 'lead_form' || $type === 'phone_cta_strip') {
                return null;
            }
        }

        // Editor reads pass $useCache=false: the operation instance outlives a single render, so the
        // per-site memo would otherwise pin a pre-publish null for the rest of the process.
        $homeLeadForm = $useCache ? $this->findHomeLeadForm($site) : $this->resolveHomeLeadForm($site);
        if (! $homeLeadForm) {
            return null;
        }

        // Rewrite the title + intro to use this service page's display name
        // so the form reads as relevant ("Get a free plant & equipment hire
        // quote") instead of mirroring the home's primary-service title.
        // Extras + benefits stay as the home emitted them — they describe
        // the business's credentials which apply on every page.
        if ($page->page_type === 'projects') {
            // Projects-page nav_label is usually "Our Work", which substitutes
            // awkwardly into the service template ("Get a free Our Work quote").
            // Use natural portfolio-page phrasing instead.
            $homeLeadForm['title'] = 'Start your next project with us';
            $homeLeadForm['intro'] = "Tell us about the work you're planning and we'll come back within one business day with a no-obligation quote.";
        } else {
            $serviceName = $this->serviceDisplayName($page);
            if ($serviceName !== null) {
                $homeLeadForm['title'] = "Get a free {$serviceName} quote";
                $homeLeadForm['intro'] = "Tell us a little about your {$serviceName} project and we'll get back to you within one business day with a no-obligation quote.";
            }
        }

        // Find the last cta section and splice before it. Inject a
        // phone_cta_strip marker BEFORE the lead_form so the page reads
        // as: …content… → tel CTA → form → cta. Mirrors the home page's
        // composition pattern. Title/subtitle come from the site's
        // archetype — without them, the blade falls back to "24/7
        // Emergency Call-Out", which is the wrong framing for every
        // archetype except EmergencyTrade.
        $ctaIndex = null;
        foreach ($sections as $i => $s) {
            if (($s['type'] ?? null) === 'cta') {
                $ctaIndex = $i;
            }
        }
        $archetype = $site->businessProfile?->archetype() ?? Archetype::LocalService;
        $phoneStripCopy = $archetype->phoneCtaCopy();
        $phoneStrip = array_merge(['type' => 'phone_cta_strip'], $phoneStripCopy);
        // The injected copy is rendered on a SERVICE page: the home page's layout
        // metadata must not travel with it. `variant => null` in particular would
        // make shouldStampVariant() treat the copy as "cleared" and block the
        // service recipe (spec §Injected service forms inherit no layout metadata,
        // and §C/§D motion and previous stamps never leak across page kinds).
        foreach (array_keys($phoneStrip) as $layoutKey) {
            if (in_array($layoutKey, ['__stored_index', 'id', 'variant', '__options', '__surface'], true)
                || str_starts_with($layoutKey, '__motion')
                || str_starts_with($layoutKey, '__previous')
            ) {
                unset($phoneStrip[$layoutKey]);
            }
        }
        foreach (array_keys($homeLeadForm) as $layoutKey) {
            if (in_array($layoutKey, ['__stored_index', 'id', 'variant', '__options', '__surface'], true)
                || str_starts_with($layoutKey, '__motion')
                || str_starts_with($layoutKey, '__previous')
            ) {
                unset($homeLeadForm[$layoutKey]);
            }
        }
        return ['index' => $ctaIndex, 'block' => [$phoneStrip, $homeLeadForm]];
    }

    protected function injectServiceLeadForm(Site $site, ?GeneratedPage $page, array $sections): array
    {
        $injected = $this->injectedServiceBlock($site, $page, $sections);
        if ($injected === null) {
            return $sections;
        }
        if ($injected['index'] === null) {
            return array_merge($sections, $injected['block']);
        }
        array_splice($sections, $injected['index'], 0, $injected['block']);

        return $sections;
    }


    public function wouldInjectServiceLeadForm(Site $site, GeneratedPage $page): bool
    {
        if (! is_string($page->page_type) || $page->page_type === '') {
            return false;
        }

        if ($page->kind !== null) {
            if ($page->kind !== PageKind::Service) {
                return false;
            }
        } elseif (in_array($page->page_type, ['home', 'about', 'contact'], true)) {
            return false;
        }

        return $site->businessProfile?->leadFormPolicy()->includesServices() === true;
    }

    /**
     * Best-effort display name for the service a page represents. Preference
     * order: the generated service_display_name (cleanest — "Plant &
     * Equipment Hire" rather than a slug-derived "Plant Equipment Hire
     * Perth"), then nav_label, then the page_type stripped of the trailing
     * location suffix and title-cased. Returns null when none are useful.
     */
    protected function serviceDisplayName(GeneratedPage $page): ?string
    {
        // publishedRevision is the authoritative source that the
        // rest of PageRenderer uses (line 54). Reading from
        // $page->content_data here would silently serve a stale
        // display name when a composer / regen updated the revision
        // without mirroring back — matches the fix applied to the
        // admin read paths.
        $content = $page->publishedRevision?->content_data
            ?? $page->content_data
            ?? [];
        $fromContent = $content['service_display_name']
            ?? $content['meta']['service_display_name']
            ?? null;
        if (is_string($fromContent) && trim($fromContent) !== '') {
            return trim($fromContent);
        }

        if (is_string($page->nav_label) && trim($page->nav_label) !== '') {
            return trim($page->nav_label);
        }

        // Last resort: slug-derived. Strip the trailing location suffix
        // ("-perth", "-wigan", etc.) if we can infer it from the site.
        $slug = (string) $page->page_type;
        $location = strtolower((string) ($page->site?->location ?? ''));
        if ($location !== '') {
            $locSlug = Str::slug($location);
            $slug = preg_replace('/-'.preg_quote($locSlug, '/').'$/i', '', $slug);
        }
        $clean = trim(str_replace('-', ' ', (string) $slug));

        return $clean === '' ? null : ucwords($clean);
    }

    /**
     * When the page already exposes an inline form (lead_form or
     * contact_form), rewrite any trailing `cta` section's button into a
     * phone CTA. A "contact us" button on a page that already has a form
     * feels redundant and dilutes the primary conversion path; the phone
     * gives the user a second, distinct route.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $profile
     * @return array<int, array<string, mixed>>
     */
    protected function swapCtaToPhoneWhenFormPresent(array $sections, array $profile): array
    {
        $phone = $profile['contact']['phones'][0] ?? null;
        if (! is_string($phone) || $phone === '') {
            return $sections;
        }

        if (! $this->pageHasFormSection($sections)) {
            return $sections;
        }

        $telHref = 'tel:'.preg_replace('/\s+/', '', $phone);
        foreach ($sections as $i => $s) {
            if (($s['type'] ?? null) === 'cta') {
                $sections[$i]['button_label'] = "Call {$phone}";
                $sections[$i]['button_url'] = $telHref;
            }
        }

        return $sections;
    }

    /**
     * True when the rendered section list includes a lead_form or
     * contact_form (injected service-page copies and absorbed contact
     * forms included — both remain in this list).
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    protected function pageHasFormSection(array $sections): bool
    {
        foreach ($sections as $s) {
            $t = $s['type'] ?? null;
            if ($t === 'lead_form' || $t === 'contact_form') {
                return true;
            }
        }

        return false;
    }

    /**
     * Render-time projects-page layout switch. Triggers only on the
     * projects page; on every other page the section list is returned
     * unchanged. When the site's projects_layout is `case_studies`,
     * drops the `project_gallery` (tile grid) section and remaps
     * `case_study_highlights` to `project_case_studies` (the long-form
     * narrative blade). Both blades read the same project_items rows
     * by id, so the toggle is non-destructive and bidirectional.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function applyProjectsLayout(Site $site, ?GeneratedPage $page, array $sections): array
    {
        if (! $page || $page->page_type !== 'projects') {
            return $sections;
        }
        if ($site->projects_layout !== ProjectsLayout::CaseStudies) {
            return $sections;
        }

        $out = [];
        foreach ($sections as $section) {
            $type = $section['type'] ?? null;
            if ($type === 'project_gallery') {
                continue;
            }
            if ($type === 'case_study_highlights') {
                $section['type'] = 'project_case_studies';
            }
            $out[] = $section;
        }

        return $out;
    }

    /**
     * Render-time home-page layout preset. Only the home page is touched;
     * Classic is the identity transform. For every other preset, stamp the
     * recipe's per-type `variant` onto home sections that don't already
     * carry an explicit variant (content_data overrides always win — that
     * precedence is the contract per-section editor controls build on).
     *
     * In stacked mode the merged section list carries sections from every
     * page tagged with __page_type; only home-tagged (or untagged) sections
     * are stamped.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function applyHomeLayout(Site $site, ?GeneratedPage $page, array $sections, string $mode = 'public'): array
    {
        if (! $page || $page->page_type !== 'home') {
            return $sections;
        }

        $recipe = $this->pageLayoutRegistry->resolveForPage($site, $page, 'home');
        if (! is_array($recipe)) {
            return $sections;
        }

        $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
        $surfaces = is_array($recipe['surfaces'] ?? null) ? $recipe['surfaces'] : [];
        if ($variants === [] && ($recipe['insert_sections'] ?? []) === [] && $surfaces === []) {
            return $sections;
        }

        $inserts = is_array($recipe['insert_sections'] ?? null) ? $recipe['insert_sections'] : [];
        $options = is_array($recipe['options'] ?? null) ? $recipe['options'] : [];
        $heroMode = $recipe['hero_mode'] ?? 'force';
        $policy = $recipe['eyebrow_policy'] ?? 'all';
        $eyebrowFamilies = $this->pageLayoutRegistry->fileBackedFamiliesFor('home');
        $eyebrowSections = array_values(array_intersect(
            is_array($recipe['eyebrow_sections'] ?? null) ? $recipe['eyebrow_sections'] : [],
            $eyebrowFamilies,
        ));

        if (in_array('portfolio_strip', $inserts, true)) {
            // Stacked mode merges every page's sections; only home-scoped
            // strips count as "already present" (variant stamping is similarly scoped).
            $homeStripIndexes = [];
            foreach ($sections as $i => $s) {
                if (is_array($s)
                    && ($s['type'] ?? null) === 'portfolio_strip'
                    && ($s['__page_type'] ?? 'home') === 'home'
                ) {
                    $homeStripIndexes[] = $i;
                }
            }

            if ($homeStripIndexes === []) {
                // Public: Published only. Admin-preview / other non-public:
                // non-archived (Draft visible), matching $itemsById hydration.
                $itemIds = $this->showcaseBandItemIds($site, $mode);

                // Skip the INSERT in admin-edit: editable field paths are
                // index-positional into content_data.sections, so a render-time
                // splice desynchronises later sections and edits write wrong.
                // Variant stamping below still runs in all modes.
                if ($itemIds !== [] && $mode !== 'admin-edit') {
                    // After the last home what-we-offer anchor (mirrors the
                    // reviews_summary placement rule in ArchetypeComposer);
                    // index 1 (below hero) when no anchor exists. Skip non-home
                    // sections so a service page's services block cannot pull
                    // the band out of the home group in stacked mode.
                    $insertAt = 1;
                    foreach ($sections as $i => $s) {
                        if (($s['__page_type'] ?? 'home') !== 'home') {
                            continue;
                        }
                        if (in_array($s['type'] ?? '', ['services', 'reviews_summary', 'trust'], true)) {
                            $insertAt = $i + 1;
                        }
                    }
                    array_splice($sections, $insertAt, 0, [[
                        'type' => 'portfolio_strip',
                        'item_ids' => $itemIds,
                    ]]);
                }
            } else {
                // Facet B: traditional_craftsman composes a bare portfolio_strip
                // (no item_ids). Backfill from the same mode-aware query so
                // Showcase can stamp dark-band onto real ProjectItems. Strips
                // that already carry item_ids are left alone.
                foreach ($homeStripIndexes as $i) {
                    $existingIds = $sections[$i]['item_ids'] ?? null;
                    if (is_array($existingIds) && $existingIds !== []) {
                        continue;
                    }
                    $itemIds = $this->showcaseBandItemIds($site, $mode);
                    if ($itemIds !== []) {
                        $sections[$i]['item_ids'] = $itemIds;
                    }
                }
            }
        }

        foreach ($sections as &$section) {
            if (! is_array($section)) {
                continue;
            }
            if (($section['__page_type'] ?? 'home') !== 'home') {
                continue;
            }
            $type = $section['type'] ?? '';
            if (! is_string($type)) {
                continue;
            }
            // Empty portfolio_strip under Showcase: leave the variant unwritten
            // so the light strip's self-gate is preserved (no empty dark band).
            // Keep the family in $variants so __options still stamp.
            $skipEmptyDarkBand = $type === 'portfolio_strip' && ($variants[$type] ?? null) === 'dark-band'
                && (! is_array($section['item_ids'] ?? null) || $section['item_ids'] === []);
            // default = never write the hero variant; force (and absent) use
            // shouldStampVariant inside stampSection. Family stays in the map
            // so __options / __surface still follow the existing gates.
            $skipVariantWrite = $skipEmptyDarkBand
                || ($type === 'hero' && $heroMode === 'default');
            $this->stampSection($section, $type, $variants, $options, $surfaces, $skipVariantWrite);
        }
        unset($section);

        if ($policy === 'first-only' && $eyebrowSections !== []) {
            $seenEyebrow = false;
            foreach ($sections as $i => $section) {
                if (! is_array($section) || ($section['__page_type'] ?? 'home') !== 'home') {
                    continue;
                }
                $type = $section['type'] ?? null;
                if (! is_string($type) || ! in_array($type, $eyebrowSections, true)) {
                    continue;
                }
                if ($seenEyebrow) {
                    $sections[$i]['__suppress_eyebrow'] = true;
                }
                $seenEyebrow = true;
            }
        }

        return $sections;
    }

    /**
     * Thin wrapper kept so ServicesLayoutStampTest (and other reflection
     * callers) continue to invoke applyServicesLayout unchanged.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  list<string>|null  $servicePageTypes
     * @return array<int, array<string, mixed>>
     */
    protected function applyServicesLayout(Site $site, ?GeneratedPage $page, array $sections, ?array $servicePageTypes = null): array
    {
        return $this->applyPageKindLayout($site, $page, $sections, 'service', $servicePageTypes);
    }

    /**
     * Render-time page-kind layout preset. Mirrors applyHomeLayout:
     * kind pages only; 'classic' (or any unknown/invalid key) is the
     * identity transform — fail-closed so a stale bespoke key can never
     * 500 a live page. Stamps `variant` per section type only when the
     * section doesn't already carry a `variant` key (explicit content_data
     * variants win, including `'variant' => null` meaning cleared — same
     * contract as applyHomeLayout), plus `__suppress_eyebrow`
     * per the recipe's eyebrow_policy.
     *
     * CRITICAL — both render modes: in multi-page mode the resolution page
     * IS the kind page, but renderStacked passes the HOME page with
     * per-section __page_type tags (the bug-#8 trap: an early return on
     * $page->page_type never fires in one-page mode). So gate PER SECTION
     * against the kind's page types, never on the resolution page.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  list<string>|null  $pageTypes
     * @return array<int, array<string, mixed>>
     */
    protected function applyPageKindLayout(Site $site, ?GeneratedPage $page, array $sections, string $kind, ?array $pageTypes = null): array
    {
        $kindTypes = $pageTypes ?? $this->pageTypesForKind($site, $kind);
        if ($kindTypes === []) {
            return $sections;
        }
        $defaultType = $page?->page_type;

        // page_type => recipe|null, from a site-scoped map memoised on this
        // renderer (service + about share one hydration). Archived pages
        // are excluded; duplicate page_types keep lowest sort_order then id.
        $pagesByType = $this->kindPagesByType($site, $kindTypes);
        $recipeByType = [];
        foreach ($kindTypes as $pt) {
            $p = $pagesByType->get($pt) ?? ($page && $page->page_type === $pt ? $page : null);
            $recipeByType[$pt] = $this->pageLayoutRegistry->resolveForPage($site, $p, $kind);
        }
        if (array_filter($recipeByType) === []) {
            return $sections;
        }

        $seenEyebrowByPage = [];
        foreach ($sections as $i => $section) {
            if (! is_array($section)) {
                continue;
            }
            $sectionPageType = $section['__page_type'] ?? $defaultType;
            if (! in_array($sectionPageType, $kindTypes, true)) {
                continue;
            }
            $recipe = $recipeByType[$sectionPageType] ?? null;
            if (! is_array($recipe)) {
                continue;
            }

            $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
            $options = is_array($recipe['options'] ?? null) ? $recipe['options'] : [];
            $type = $section['type'] ?? '';
            $this->stampSection($sections[$i], $type, $variants, $options, null);

            $policy = $recipe['eyebrow_policy'] ?? 'all';
            $eyebrowSections = is_array($recipe['eyebrow_sections'] ?? null) ? $recipe['eyebrow_sections'] : array_keys($variants);
            if ($policy === 'first-only' && in_array($type, $eyebrowSections, true)) {
                $pageKey = (string) $sectionPageType;
                if ($seenEyebrowByPage[$pageKey] ?? false) {
                    $sections[$i]['__suppress_eyebrow'] = true;
                }
                $seenEyebrowByPage[$pageKey] = true;
            }
        }

        return $sections;
    }

    /**
     * One stamp rule for every kind: variant via shouldStampVariant
     * (explicit-wins + dead-token bypass) unless $skipVariantWrite,
     * surfaces on isset($surfaces[$type]) + variantConsumesSurface even
     * when the recipe names no variant for the family, options on
     * isset($variants[$type]) even when the variant write was suppressed.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $variants
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $surfaces
     */
    protected function stampSection(array &$section, string $type, array $variants, array $options, ?array $surfaces, bool $skipVariantWrite = false): void
    {
        if ($type === '') {
            return;
        }
        if (! $skipVariantWrite && isset($variants[$type])
            && $this->pageLayoutRegistry->shouldStampVariant($section, $type)) {
            $section['variant'] = $variants[$type];
        }
        if (is_array($surfaces) && isset($surfaces[$type]) && is_string($surfaces[$type])
            && $this->pageLayoutRegistry->variantConsumesSurface($type, $section['variant'] ?? null, $surfaces[$type])) {
            $section['__surface'] = $surfaces[$type];
        }
        if (isset($variants[$type]) && $options !== []) {
            $section['__options'] = $options;
        }
    }

    /** @var array<int, array<int, string>> */
    private array $servicePageTypesMemo = [];

    /** @var array<int, Collection<string, GeneratedPage>> */
    private array $kindPagesByTypeMemo = [];

    /** @return list<string> */
    protected function servicePageTypesFor(Site $site): array
    {
        return $this->servicePageTypesMemo[$site->id] ??= $this->pageLayoutRegistry->servicePageTypesFor($site);
    }

    /**
     * @param  list<string>  $kindTypes
     * @return Collection<string, GeneratedPage>
     */
    protected function kindPagesByType(Site $site, array $kindTypes): Collection
    {
        if (! $site->id || $kindTypes === []) {
            return collect();
        }

        $all = $this->kindPagesByTypeMemo[$site->id] ??= GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'page_type', 'layout_preset_key', 'sort_order', 'parent_id'])
            ->unique('page_type')
            ->keyBy('page_type');

        // Eloquent Collection::only() keys by id; filter by page_type instead.
        return $all->filter(
            fn (GeneratedPage $page): bool => in_array($page->page_type, $kindTypes, true),
        );
    }

    /**
     * @return list<string>
     */
    protected function pageTypesForKind(Site $site, string $kind): array
    {
        // Same map as PageLayoutRegistry::pageTypesForKind, but the service
        // arm goes through the per-request memo (r1b: hydrate once per render).
        return match ($kind) {
            'about' => ['about'],
            'home' => ['home'],
            'projects' => ['projects'],
            'project_detail' => $this->pageLayoutRegistry->pageTypesForKind($site, 'project_detail'),
            default => $this->servicePageTypesFor($site),
        };
    }

    /**
     * Site-scoped project item ids for Showcase portfolio_strip insert/backfill.
     * public → Published only; any other mode → non-archived (Draft visible).
     *
     * @return list<int>
     */
    protected function showcaseBandItemIds(Site $site, string $mode): array
    {
        return ProjectItem::query()
            ->where('site_id', $site->id)
            ->when(
                $mode === 'public',
                fn ($q) => $q->where('status', ProjectItemStatus::Published->value),
                fn ($q) => $q->where('status', '!=', ProjectItemStatus::Archived->value),
            )
            ->whereNotNull('image_id')
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('id')
            ->all();
    }

    /**
     * Per-renderer cache of the resolved home lead-form section, keyed
     * by site id. renderStacked calls injectServiceLeadForm once per
     * service page; without memoisation findHomeLeadForm issues three
     * queries (SiteVersionCurrent, SiteVersion, PageRevision) per
     * service page, turning a 12-service site into ~36 extra round-trips.
     *
     * @var array<int, array<string, mixed>|null>
     */
    protected array $homeLeadFormCache = [];

    /**
     * Load the home page's lead_form section for the current published
     * revision, or null if home has no lead_form.
     *
     * @return array<string, mixed>|null
     */
    protected function findHomeLeadForm(Site $site): ?array
    {
        if (array_key_exists($site->id, $this->homeLeadFormCache)) {
            return $this->homeLeadFormCache[$site->id];
        }

        $result = $this->resolveHomeLeadForm($site);

        return $this->homeLeadFormCache[$site->id] = $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveHomeLeadForm(Site $site): ?array
    {
        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        if (! $current) {
            return null;
        }
        $version = SiteVersion::find($current->version_id);
        if (! $version) {
            return null;
        }
        $homepageId = $version->composition['homepage_page_id'] ?? null;
        if (! $homepageId) {
            return null;
        }
        $pinned = collect($version->page_revisions)->firstWhere('page_id', (int) $homepageId);
        if (! $pinned) {
            return null;
        }
        $revision = PageRevision::find($pinned['revision_id']);
        if (! $revision) {
            return null;
        }
        foreach (($revision->content_data['sections'] ?? []) as $s) {
            if (($s['type'] ?? null) === 'lead_form') {
                return $s;
            }
        }

        return null;
    }

    /**
     * Build a slug → href lookup for pages on this site.
     *
     * Public mode (and admin-edit on the public host): uses the pinned page set
     * from site_versions_current, without the archived_at filter, so sections
     * that cross-link to service pages still work even after an editor archives
     * a page that the published version still pins.
     *
     * Admin mode (preview/edit on the admin host): keeps the existing behaviour
     * of querying all live, non-archived pages.
     *
     * @return array<string, string>
     */
    protected function resolvePagesBySlug(Site $site, array $composition, string $mode, bool $publicHostNav, bool $signedNav = false, ?string $parentOrigin = null, bool $rootPagesOnly = false): array
    {
        $publicPrefix = config('site.public_route_prefix', '');
        $homepageId = $composition['homepage_page_id'] ?? null;
        $hrefMode = $publicHostNav ? 'public' : $mode;

        // Public mode (or admin-edit served on the customer's public host):
        // resolve pages from the published version's pinned set, ignoring archived_at.
        // Reuse the version + pinned-pages collection cached by resolvePublic
        // during this render — otherwise we'd requery site_versions_current,
        // site_versions, and generated_pages identically on every helper call.
        if ($mode === 'public' || $publicHostNav) {
            $pages = collect();
            if (($cachedPages = $this->cachedPinnedPagesFor($site)) !== null) {
                $pages = $cachedPages->values()
                    ->when($rootPagesOnly, fn (Collection $pages) => $pages->whereNull('parent_id'));
            } else {
                $current = SiteVersionCurrent::where('site_id', $site->id)->first();
                if ($current) {
                    $version = SiteVersion::find($current->version_id);
                    if ($version) {
                        $pinnedIds = collect($version->page_revisions)->pluck('page_id')->filter()->all();
                        if (! empty($pinnedIds)) {
                            $pages = GeneratedPage::whereIn('id', $pinnedIds)
                                ->when($rootPagesOnly, fn (Builder $query) => $query->whereNull('parent_id'))
                                ->get(['id', 'parent_id', 'page_type']);
                        }
                    }
                }
            }
        } else {
            // Admin-preview / admin-edit on admin host: live pages only, no archived.
            $pages = GeneratedPage::where('site_id', $site->id)
                ->whereNull('archived_at')
                ->when($rootPagesOnly, fn (Builder $query) => $query->whereNull('parent_id'))
                ->get(['id', 'parent_id', 'page_type']);
        }

        $map = [];
        foreach ($pages as $page) {
            if (! $page->page_type) {
                continue;
            }
            $publicPath = $page->publicPath();
            $map[$publicPath] = $this->buildPageHref(
                pageId: $page->id,
                pageType: $publicPath,
                isHomepage: $page->id === $homepageId,
                mode: $hrefMode,
                publicPrefix: $publicPrefix,
                siteId: $site->id,
                signedNav: $signedNav,
                parentOrigin: $parentOrigin,
            );
        }

        return $map;
    }

    /**
     * Footer columns and related_* strips are the only consumers of the
     * pinned-page index. Skip the revision whereIn (and signed-URL mint
     * per page in editor-preview) when neither is present.
     *
     * @param  array<string, mixed>  $composition
     * @param  array<int, mixed>  $sections
     */
    protected function needsPinnedPages(array $composition, array $sections): bool
    {
        $columns = $composition['footer']['columns'] ?? [];
        if (is_array($columns) && $columns !== []) {
            return true;
        }

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $type = $section['type'] ?? null;
            if (is_string($type) && str_starts_with($type, 'related_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current SiteVersion's pinned pages, shaped for footer columns and
     * render-time related-content strips. Labels and URLs are resolved
     * here so slug/title edits survive without rewriting composition.
     *
     * Strip grouping params are sourced per mode:
     *   - public / renderVersion / any mode that has a SiteVersion pin:
     *     the pinned PageRevision's content_data (one whereIn by pin
     *     revision_id). Unpublished draft writes must not change public
     *     grouping — generated_pages.content_data is a draft mirror.
     *   - admin-preview / admin-edit when no pin/revision exists:
     *     GeneratedPage.content_data (current draft-mirror behaviour).
     *
     * @return Collection<int, array{id: int, page_type: string, kind: string|null, nav_label: string|null, url: string, params: array<string, mixed>}>
     */
    protected function resolvePinnedPages(
        Site $site,
        array $composition,
        string $mode,
        bool $publicHostNav,
        bool $signedNav = false,
        ?string $parentOrigin = null,
    ): Collection {
        $version = $this->publicVersionCache['version'] ?? null;
        $pagesById = $version !== null ? $this->cachedPinnedPagesFor($site) : null;

        if ($version !== null && (int) $version->site_id !== (int) $site->id) {
            $version = null;
        }

        if ($version === null) {
            $current = SiteVersionCurrent::where('site_id', $site->id)->first();
            $version = $current ? SiteVersion::find($current->version_id) : null;
        }

        if ($version === null) {
            return collect();
        }

        if ($pagesById === null) {
            $pinnedIds = collect($version->page_revisions)->pluck('page_id')->filter()->all();
            $pagesById = $pinnedIds === []
                ? collect()
                : GeneratedPage::query()->whereIn('id', $pinnedIds)->get()->keyBy('id');
        }

        $revisionIds = collect($version->page_revisions)
            ->pluck('revision_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $revisionsById = $revisionIds === []
            ? collect()
            : PageRevision::query()
                ->whereIn('id', $revisionIds)
                ->get(['id', 'page_id', 'content_data'])
                ->keyBy('id');

        $homepageId = $composition['homepage_page_id'] ?? null;
        $hrefMode = $publicHostNav ? 'public' : $mode;
        $publicPrefix = config('site.public_route_prefix', '');

        return collect($version->page_revisions)
            ->map(function (mixed $pin) use ($pagesById, $revisionsById, $homepageId, $hrefMode, $publicPrefix, $site, $signedNav, $parentOrigin) {
                if (! is_array($pin)) {
                    return null;
                }

                $page = $pagesById->get((int) ($pin['page_id'] ?? 0));
                if (! $page instanceof GeneratedPage || ! is_string($page->page_type) || $page->page_type === '') {
                    return null;
                }

                $kind = $page->kind instanceof PageKind ? $page->kind->value : $page->kind;
                $revision = $revisionsById->get((int) ($pin['revision_id'] ?? 0));

                return [
                    'id' => $page->id,
                    'page_type' => $page->page_type,
                    'kind' => is_string($kind) ? $kind : null,
                    'nav_label' => $page->nav_label,
                    'url' => $this->buildPageHref(
                        pageId: $page->id,
                        pageType: $page->publicPath(),
                        isHomepage: $page->id === $homepageId,
                        mode: $hrefMode,
                        publicPrefix: $publicPrefix,
                        siteId: $site->id,
                        signedNav: $signedNav,
                        parentOrigin: $parentOrigin,
                    ),
                    'params' => $this->pageParams(
                        $page,
                        $revision instanceof PageRevision ? $revision : null,
                    ),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Params used by related-content strip grouping.
     *
     * Prefer the pinned revision (public + any mode that resolved a pin).
     * Fall back to the live page row only when no pin/revision exists
     * (admin-preview / admin-edit on an unpublished or unpinned page).
     *
     * @return array<string, mixed>
     */
    protected function pageParams(GeneratedPage $page, ?PageRevision $pinnedRevision = null): array
    {
        $content = $pinnedRevision?->content_data ?? $page->content_data ?? [];
        $params = $content['params'] ?? $content['meta']['params'] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Build the href the brand/logo link should point at. Always the site's
     * homepage, regardless of nav ordering or group layout.
     *
     * - public / public-host-edit: '/' (same-host public path)
     * - admin-preview / admin-edit on admin host: the homepage's admin preview
     *   route so clicking the logo from a service page navigates home.
     */
    protected function resolveHomeHref(Site $site, array $composition, string $mode, bool $publicHostNav, bool $signedNav = false, ?string $parentOrigin = null): string
    {
        if ($mode === 'public' || $publicHostNav) {
            $prefix = config('site.public_route_prefix', '');

            return $prefix === '' ? '/' : $prefix.'/';
        }

        $homepageId = $composition['homepage_page_id'] ?? null;
        if (! $homepageId) {
            return '#';
        }

        // 9B editor-preview iframe: same signing as the nav links so the logo
        // home link stays inside the iframe origin. parent_origin is threaded
        // from EditorPreviewController so cross-page nav inside the iframe
        // keeps the same parent surface (agent vs customer) — without it,
        // every signed-nav URL falls back to agent_domain in
        // EditorPreviewController, breaking the bridge for customer-surface
        // editors after the first page.
        if ($signedNav) {
            $params = ['site' => $site->id, 'page' => $homepageId];
            if ($parentOrigin !== null) {
                $params['parent_origin'] = $parentOrigin;
            }

            return URL::temporarySignedRoute(
                'editor-preview.show',
                now()->addHours(8),
                $params,
            );
        }

        $editParam = $mode === 'admin-edit' ? '?edit=1' : '';

        return "/sites/{$site->id}/pages/{$homepageId}/preview{$editParam}";
    }

    /**
     * Resolve which revision + composition to use for a given mode.
     *
     * Public mode: reads strictly from site_versions_current — does NOT consult
     * the live page row's archived_at. The published version may still pin a
     * page that the editor later archived; rolling back must continue to render
     * those pages.
     *
     * @return array{page: GeneratedPage, content: array, composition: array}
     */
    public function resolve(Site $site, int $pageId, string $mode): array
    {
        if ($mode === 'public') {
            return $this->resolvePublic($site, $pageId);
        }

        // admin-preview / admin-edit — these consult the live (current-state)
        // pages table because the editor is working on now-state, not a past version.
        $page = GeneratedPage::where('site_id', $site->id)
            ->whereNull('archived_at')
            ->find($pageId);

        if (! $page) {
            abort(404);
        }

        $draft = SiteDraft::where('site_id', $site->id)->first();
        $composition = $draft?->composition ?? [];

        $revisionId = $page->draft_revision_id ?? $page->published_revision_id;
        $revision = $revisionId ? PageRevision::find($revisionId) : null;

        return [
            'page' => $page,
            'content' => $revision?->content_data ?? ($page->content_data ?? []),
            'composition' => $composition,
        ];
    }

    /**
     * Public-mode resolution: never look at live page archived_at.
     * The site_versions_current → SiteVersion.page_revisions array is the
     * authoritative pinned set; pages absent from it are 404.
     */
    /**
     * Preloaded SiteVersion + pinned-pages map keyed by call-chain reuse.
     * Populated by resolvePublic() and consumed by resolvePagesBySlug() /
     * resolveNavItems() in the same render, so we don't re-query site_versions
     * or generated_pages in each helper.
     *
     * @var array{version: SiteVersion, pagesById: Collection<int, GeneratedPage>}|null
     */
    protected ?array $publicVersionCache = null;

    /**
     * Memoised pinned pages for $site, or null when the cache is empty or
     * belongs to a different site. Every consumer must go through this
     * accessor — reading publicVersionCache directly risks serving one
     * site's pages inside another site's render if an instance is ever
     * reused across tenants.
     *
     * @return Collection<int, GeneratedPage>|null
     */
    protected function cachedPinnedPagesFor(Site $site): ?Collection
    {
        $version = $this->publicVersionCache['version'] ?? null;
        if ($version === null || (int) $version->site_id !== (int) $site->id) {
            return null;
        }

        return $this->publicVersionCache['pagesById'] ?? null;
    }

    protected function resolvePublic(Site $site, int $pageId): array
    {
        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        if (! $current) {
            abort(404);
        }
        $version = SiteVersion::find($current->version_id);
        if (! $version) {
            abort(404);
        }

        $pinned = collect($version->page_revisions)->firstWhere('page_id', $pageId);
        if (! $pinned) {
            abort(404);
        }

        // Load every pinned page in ONE query (covers the requested page + the
        // pagesBySlug/navItems resolvers downstream). Without this cache those
        // two helpers re-run site_versions_current + site_versions +
        // generated_pages whereIn, doubling DB round-trips per render.
        $pinnedIds = collect($version->page_revisions)->pluck('page_id')->filter()->all();
        $pagesById = GeneratedPage::whereIn('id', $pinnedIds)->get()->keyBy('id');

        $page = $pagesById->get($pageId);
        if (! $page) {
            abort(404);
        }

        $this->publicVersionCache = [
            'version' => $version,
            'pagesById' => $pagesById,
        ];

        $revision = PageRevision::find($pinned['revision_id']);

        return [
            'page' => $page,
            'content' => $revision?->content_data ?? [],
            'composition' => $version->composition,
        ];
    }

    /**
     * Resolve nav items into render-ready shape with hrefs.
     *
     * Each result item: ['type' => string, 'label' => string, 'href' => string].
     *
     * - `page` items: resolve page_id → page_type, build URL using mode prefix
     * - `shop` / `news`: hard-coded paths (these features mount their own routes)
     * - `external`: pass-through url
     *
     * Mode prefix:
     * - `public`: `/__site_v2/{page_type}` while the versioned renderer is
     *   rolling out (cutover removes the prefix; this method centralises that change)
     * - `admin-preview` / `admin-edit`: links go to other pages' admin previews
     *   so merchants can navigate around their site while editing
     */
    protected function resolveNavItems(Site $site, array $composition, string $mode, bool $publicHostNav = false, bool $signedNav = false, ?string $parentOrigin = null): array
    {
        $publicPrefix = config('site.public_route_prefix', '/__site_v2');
        $items = $composition['nav']['items'] ?? [];
        $homepageId = $composition['homepage_page_id'] ?? null;

        if (! $site->shopEnabled()) {
            $items = $this->withoutShopNavItems($items);
        }

        // Cache page_id → page_type for nav resolution. Reuse the public-render
        // cache if resolvePublic populated it (one GeneratedPage query already
        // loaded every pinned page on this site).
        $cachedPagesById = $this->cachedPinnedPagesFor($site);

        $pageIds = collect($items)->where('type', 'page')->pluck('page_id')->filter()->all();
        if ($homepageId) {
            $pageIds[] = $homepageId;
        }

        if ($cachedPagesById) {
            $pageTypes = $cachedPagesById
                ->mapWithKeys(fn (GeneratedPage $page): array => [$page->id => $page->publicPath()])
                ->all();
        } else {
            $pageTypes = $pageIds
                ? GeneratedPage::whereIn('id', $pageIds)
                    ->get(['id', 'page_type'])
                    ->mapWithKeys(fn (GeneratedPage $page): array => [$page->id => $page->publicPath()])
                    ->all()
                : [];
        }

        // Collect any page_ids referenced inside group children so we can
        // hydrate page_type for them in one query.
        $childPageIds = collect($items)
            ->where('type', 'group')
            ->flatMap(fn ($g) => collect($g['children'] ?? [])->pluck('page_id'))
            ->filter()
            ->all();
        if (! empty($childPageIds) && ! $cachedPagesById) {
            $pageTypes += GeneratedPage::whereIn('id', $childPageIds)
                ->get(['id', 'page_type'])
                ->mapWithKeys(fn (GeneratedPage $page): array => [$page->id => $page->publicPath()])
                ->all();
        }

        $hasManualGroup = collect($items)->contains(fn ($i) => ($i['type'] ?? null) === 'group');

        // When admin-edit is served on the customer's public host, nav links
        // should stay on the same host as public paths ('/', '/{slug}') — the
        // edit_session cookie keeps the browser in edit mode across clicks.
        // buildPageHref treats 'public' mode as same-host; so when
        // $publicHostNav is true, pass 'public' as the mode for href building.
        $hrefMode = $publicHostNav ? 'public' : $mode;

        // Look up footer_label per page so each resolved nav item can
        // carry both the header label (for the nav bar) and the
        // footer label (for the quick-links grid). Footer gracefully
        // falls back to the nav label when footer_label is null
        // (pre-phase-10 content, or admin-overridden nav).
        $footerLabels = GeneratedPage::where('site_id', $site->id)
            ->pluck('footer_label', 'id')
            ->all();

        $resolved = collect($items)->map(function (array $item) use ($hrefMode, $publicPrefix, $pageTypes, $site, $homepageId, $footerLabels, $signedNav, $parentOrigin) {
            $type = $item['type'] ?? 'page';

            if ($type === 'group') {
                $children = collect($item['children'] ?? [])->map(function (array $c) use ($hrefMode, $publicPrefix, $pageTypes, $site, $homepageId, $footerLabels, $signedNav, $parentOrigin) {
                    $pageId = (int) ($c['page_id'] ?? 0);
                    $navLabel = $c['label'] ?? $c['nav_label'] ?? '';

                    return [
                        'type' => 'page',
                        'label' => $navLabel,
                        'footer_label' => ($footerLabels[$pageId] ?? null) ?: $navLabel,
                        'href' => $this->buildPageHref(
                            pageId: $pageId,
                            pageType: $pageTypes[$pageId] ?? null,
                            isHomepage: $pageId === $homepageId,
                            mode: $hrefMode,
                            publicPrefix: $publicPrefix,
                            siteId: $site->id,
                            signedNav: $signedNav,
                            parentOrigin: $parentOrigin,
                        ),
                        'page_type' => $pageTypes[$pageId] ?? null,
                    ];
                })->all();

                $groupLabel = $item['label'] ?? $item['nav_label'] ?? '';

                return [
                    'type' => 'group',
                    'label' => $groupLabel,
                    'footer_label' => $groupLabel,
                    'href' => '#',
                    'page_type' => null,
                    'children' => $children,
                ];
            }

            $href = match ($type) {
                // Shop is a host-routed feature, not a page, so it has no signed preview URL.
                // Inside the editor-preview iframe (signed nav) the demo points the link at the
                // live storefront instead of the portal host, where /shop is a framed 404.
                'shop' => $signedNav && config('demo.enabled')
                    ? 'https://'.config('demo.site_host').'/shop'
                    : '/shop',
                'news' => '/news',
                'external' => $item['url'] ?? '#',
                'page' => $this->buildPageHref(
                    pageId: (int) ($item['page_id'] ?? 0),
                    pageType: $pageTypes[$item['page_id'] ?? null] ?? null,
                    isHomepage: ($item['page_id'] ?? null) === $homepageId,
                    mode: $hrefMode,
                    publicPrefix: $publicPrefix,
                    siteId: $site->id,
                    signedNav: $signedNav,
                    parentOrigin: $parentOrigin,
                ),
                default => '#',
            };

            $pageId = (int) ($item['page_id'] ?? 0);
            $navLabel = $item['label'] ?? $item['nav_label'] ?? '';

            return [
                'type' => $type,
                'label' => $navLabel,
                'footer_label' => $type === 'page' ? (($footerLabels[$pageId] ?? null) ?: $navLabel) : $navLabel,
                'href' => $href,
                'page_id' => $type === 'page' ? $pageId : null,
                'page_type' => $type === 'page' ? ($pageTypes[$pageId] ?? null) : null,
            ];
        })->all();

        // Skip auto-grouping if composition already defines manual groups —
        // the operator's intent wins over the heuristic.
        if ($hasManualGroup) {
            return ShopNavMenu::expand($site, $resolved);
        }

        return ShopNavMenu::expand($site, $this->autoGroupServices($resolved));
    }

    /**
     * Drop stored Shop nav items (including nested children) when the flag is
     * off. Render-time only — does not mutate stored composition.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withoutShopNavItems(array $items): array
    {
        $kept = [];
        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? null) === 'shop') {
                continue;
            }
            if (($item['type'] ?? null) === 'group') {
                $item['children'] = $this->withoutShopNavItems($item['children'] ?? []);
            }
            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * When the site has more than 5 nav entries, collapse pipeline service
     * pages into a single 'Services' dropdown positioned before contact.
     * Core pages (including projects) and managed/imported services stay
     * ungrouped. Null-kind items without a loaded page fall back to the
     * legacy reserved-type list.
     */
    protected function autoGroupServices(array $items): array
    {
        if (count($items) <= 5) {
            return $items;
        }

        $pageIds = collect($items)->where('type', 'page')->pluck('page_id')->filter()->all();
        $pagesById = $pageIds !== []
            ? GeneratedPage::query()->whereIn('id', $pageIds)->get()->keyBy('id')
            : collect();

        $reservedTypes = ['home', 'about', 'contact', 'privacy', 'terms'];
        $serviceItems = [];
        $retained = [];
        $contactItem = null;

        foreach ($items as $item) {
            $pt = $item['page_type'] ?? null;
            $page = $pagesById->get($item['page_id'] ?? null);
            $isPipelineService = $page instanceof GeneratedPage
                ? ($page->isServicePage() && $page->origin === PageOrigin::Pipeline)
                : ($item['type'] === 'page' && $pt && ! in_array($pt, $reservedTypes, true));

            if ($item['type'] === 'page' && $isPipelineService) {
                $serviceItems[] = $item;

                continue;
            }
            if ($item['type'] === 'page' && $pt === 'contact') {
                $contactItem = $item;

                continue;
            }
            $retained[] = $item;
        }

        if (empty($serviceItems)) {
            return $items;
        }

        $retained[] = [
            'type' => 'group',
            'label' => 'Services',
            'href' => '#',
            'page_type' => null,
            'children' => $serviceItems,
        ];
        if ($contactItem) {
            $retained[] = $contactItem;
        }

        return $retained;
    }

    protected function buildPageHref(int $pageId, ?string $pageType, bool $isHomepage, string $mode, string $publicPrefix, int $siteId, bool $signedNav = false, ?string $parentOrigin = null): string
    {
        if ($mode === 'public') {
            // Public host-based serving (ResolvePreviewHost middleware) expects
            // canonical page paths: '/' for homepage, '/{page_type}' for others.
            // The legacy '/__site_v2' prefix is retained in config only for
            // backwards compat; when empty (the default once cutover completes)
            // hrefs become '/' and '/{page_type}' which matches the middleware's
            // path regex.
            if ($isHomepage) {
                return $publicPrefix === '' ? '/' : $publicPrefix.'/';
            }
            $slug = $pageType ?? '';

            return $publicPrefix === '' ? '/'.$slug : $publicPrefix.'/'.$slug;
        }

        // 9B editor-preview iframe context: nav must navigate WITHIN the
        // iframe, which lives on a different origin than the agents shell.
        // Mint a temporarySignedRoute URL so the iframe-side navigation
        // hits editor-preview-app and the `signed` middleware accepts the
        // signature. Same APP_KEY signs/verifies on both sides.
        // parent_origin is threaded through so the destination page's
        // EditorPreviewController echoes the right surface origin into the
        // iframe config — without it, in-iframe nav from the customer
        // surface would fall back to agent_domain and the bridge would
        // silently drop every save/publish/discard postMessage.
        if ($signedNav) {
            $params = ['site' => $siteId, 'page' => $pageId];
            if ($parentOrigin !== null) {
                $params['parent_origin'] = $parentOrigin;
            }

            return URL::temporarySignedRoute(
                'editor-preview.show',
                now()->addHours(8),
                $params,
            );
        }

        // Legacy admin-preview / admin-edit (same-origin admin host) — link
        // to admin preview route for that page so merchants can navigate
        // during editing.
        $editParam = $mode === 'admin-edit' ? '?edit=1' : '';

        return "/sites/{$siteId}/pages/{$pageId}/preview{$editParam}";
    }

    /**
     * Pick the logo URL to render in the site header. Priority:
     *   1. The explicitly selected LogoConcept (if any)
     *   2. The detected logo (scraped from the prospect site)
     *   3. The first generated logo concept
     *   4. null — layout falls back to a text wordmark
     */
    protected function resolveLogoUrl(Site $site, bool $useDraftAssets = false): ?string
    {
        return $this->resolveLogoConcept($site, $useDraftAssets)?->url();
    }

    /**
     * Overlay-arm white mark. Null unless overlay_logo_concept_id points at
     * a live concept that belongs to this site (concepts are soft-deletable
     * and the column has no FK).
     */
    protected function resolveOverlayLogoUrl(Site $site): ?string
    {
        if (array_key_exists($site->id, $this->resolvedOverlayLogoUrlBySiteId)) {
            return $this->resolvedOverlayLogoUrlBySiteId[$site->id];
        }

        $id = $site->overlay_logo_concept_id;
        $url = null;
        if ($id !== null) {
            $concept = $site->logoConcepts()->find($id);
            $url = $concept?->url();
        }

        $this->resolvedOverlayLogoUrlBySiteId[$site->id] = $url;

        return $url;
    }

    /**
     * True when the resolved header logo was stored as a transparent copy
     * (`metadata.transparent === true`). Overlay chrome uses this together
     * with {@see resolveLogoPlate()} to decide the white backing plate.
     */
    protected function resolveLogoIsTransparent(Site $site, bool $useDraftAssets = false): bool
    {
        return data_get($this->resolveLogoConcept($site, $useDraftAssets)?->metadata, 'transparent') === true;
    }

    /**
     * Overlay backing plate: keep it for opaque marks, and for transparent
     * marks that do not read on a near-black ground. Absent
     * `metadata.reads_on_dark` on a transparent concept fails to legible
     * (plate kept).
     */
    protected function resolveLogoPlate(Site $site, bool $useDraftAssets = false): bool
    {
        $metadata = $this->resolveLogoConcept($site, $useDraftAssets)?->metadata ?? [];
        if (data_get($metadata, 'transparent') !== true) {
            return true;
        }

        return data_get($metadata, 'reads_on_dark') !== true;
    }

    protected function resolveLogoConcept(Site $site, bool $useDraftAssets = false): ?LogoConcept
    {
        $cacheKey = $site->id.':'.($useDraftAssets ? 'draft' : 'live');
        if (array_key_exists($cacheKey, $this->resolvedLogoConceptBySiteId)) {
            return $this->resolvedLogoConceptBySiteId[$cacheKey];
        }

        if ($useDraftAssets) {
            $draftConcept = $this->draftAssetSelections->logoFor($site);
            if ($draftConcept !== null) {
                return $this->resolvedLogoConceptBySiteId[$cacheKey] = $draftConcept;
            }
        }

        // One query covers all three priority lookups — pick in PHP.
        // Ordering: selected > detected > first generated.
        $concepts = $site->logoConcepts()
            ->where(function ($q) {
                $q->where('is_selected', true)
                    ->orWhereIn('source', [LogoConceptSource::Detected->value, LogoConceptSource::Generated->value]);
            })
            ->orderBy('id')
            ->get(['id', 'is_selected', 'source', 'path', 'metadata']);

        $selected = $concepts->firstWhere('is_selected', true);
        $resolved = $selected
            ?? $concepts->firstWhere('source', LogoConceptSource::Detected)
            ?? $concepts->firstWhere('source', LogoConceptSource::Generated);

        $this->resolvedLogoConceptBySiteId[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * Extracts all unique, positive integer SiteMedia IDs referenced in sections
     * and repeatable entry lists (gallery image_ids, team members image_id /
     * alternate_image_id, custom section items, etc.).
     *
     * @param  array<int, mixed>  $sections
     * @return list<int>
     */
    public static function extractReferencedMediaIds(array $sections): array
    {
        $ids = [];
        $addId = function (mixed $id) use (&$ids): void {
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $intVal = (int) $id;
                if ($intVal > 0) {
                    $ids[] = $intVal;
                }
            }
        };

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            // Top-level image_ids list (gallery, portfolio, etc.)
            $imageIds = $section['image_ids'] ?? null;
            if (is_array($imageIds)) {
                foreach ($imageIds as $id) {
                    $addId($id);
                }
            }

            // Top-level scalar image pointers
            $addId($section['image_id'] ?? null);
            $addId($section['alternate_image_id'] ?? null);
            $addId($section['hover_image_id'] ?? null);
            $addId($section['hero_image_id'] ?? null);

            // Repeatable entry lists (team members, custom items, entries, etc.)
            foreach (['members', 'items', 'entries', 'staff', 'team_members', 'cards'] as $listKey) {
                $entries = $section[$listKey] ?? null;
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }
                        $addId($entry['image_id'] ?? null);
                        $addId($entry['alternate_image_id'] ?? null);
                        $addId($entry['hover_image_id'] ?? null);
                        if (is_array($entry['image_ids'] ?? null)) {
                            foreach ($entry['image_ids'] as $nestedId) {
                                $addId($nestedId);
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Hydrates SiteMedia models for all image IDs referenced in sections,
     * strictly scoped by site_id to prevent cross-tenant leakage.
     *
     * @param  array<int, mixed>  $sections
     * @return Collection<int, SiteMedia>
     */
    public function hydrateMediaById(Site $site, array $sections): Collection
    {
        if (! $site->id) {
            return collect();
        }

        $referencedImageIds = self::extractReferencedMediaIds($sections);

        return empty($referencedImageIds)
            ? collect()
            : SiteMedia::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $referencedImageIds)
                ->get()
                ->keyBy('id');
    }
}
