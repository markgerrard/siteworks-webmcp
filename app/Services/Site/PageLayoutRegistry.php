<?php

namespace App\Services\Site;

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Support\Site\ChromeRecipe;
use BackedEnum;

/**
 * Resolves the effective layout recipe for a site and page kind.
 *
 * Precedence: an active site-scoped layout_presets row matching
 * (site_id, page_kind, key) wins over the kind's config file. Classic
 * and unknown keys return null so the caller can fail closed to the
 * identity transform.
 */
class PageLayoutRegistry
{
    public const SUPPORTED_SCHEMA_VERSION = 1;

    /**
     * Home (and other inline) families validate against these tokens,
     * never view()->exists — the branches live in the stock section blades.
     *
     * @var array<string, list<string>>
     */
    public const INLINE_VARIANT_FAMILIES = [
        'hero' => ['boxed-left', 'panel-left'],
        'reviews_summary' => ['grid', 'carousel'],
        'cta' => ['accent-band', 'marquee-band'],
    ];

    /** @var list<string> */
    public const FILE_BACKED_FAMILIES = [
        'intro', 'features', 'story', 'values',
        'services', 'trust', 'process', 'portfolio_strip',
        'project_gallery', 'lead_form', 'team', 'statistics',
        'project_detail_hero', 'project_meta_band', 'project_photo_essay',
        'project_about', 'project_cta_row', 'similar_projects',
        'featured_products', 'promo_tiles', 'category_rail',
    ];

    public const MOTION_TIERS = ['none', 'subtle', 'expressive', 'cinema'];

    /**
     * Option keys and allowed values for motion personality axis (spec §C).
     *
     * @var array<string, list<mixed>>
     */
    public const MOTION_OPTIONS = [
        'motion_tier' => ['none', 'subtle', 'expressive', 'cinema'],
        'marquee_band' => [true, false],
        'split_heading_reveal' => [true, false],
        'stat_count_up' => [true, false],
        'logo_tile_hover' => [true, false],
    ];

    /**
     * Opt-in last-emitted surface adjacency (spec §D). Absent == off.
     *
     * @var array<string, list<mixed>>
     */
    public const ADJACENCY_OPTIONS = [
        'previous_surfaces' => [true, false],
    ];

    /**
     * Motion tier macro expansions to per-device options.
     *
     * @var array<string, array<string, bool>>
     */
    public const MOTION_TIER_EXPANSIONS = [
        'none' => [
            'marquee_band' => false,
            'split_heading_reveal' => false,
            'stat_count_up' => false,
            'logo_tile_hover' => false,
        ],
        'subtle' => [
            'marquee_band' => false,
            'split_heading_reveal' => false,
            'stat_count_up' => true,
            'logo_tile_hover' => true,
        ],
        'expressive' => [
            'marquee_band' => true,
            'split_heading_reveal' => true,
            'stat_count_up' => true,
            'logo_tile_hover' => true,
        ],
        'cinema' => [
            'marquee_band' => true,
            'split_heading_reveal' => true,
            'stat_count_up' => true,
            'logo_tile_hover' => true,
        ],
    ];

    public const SURFACE_VALUES = ['contrast', 'brand'];

    /**
     * family => [variant => list of surface VALUES its wrapper consumes].
     * Allowlist polarity: a surfaces key whose effective variant does not
     * consume the requested VALUE fails closed. Meta-test pins the
     * variant keys to the blades that literally read __surface.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const SURFACE_CONSUMING_VARIANTS = [
        'services' => [
            'classic' => ['contrast'], 'marker-columns' => ['contrast'],
            'numbered-rows' => ['contrast'], 'photo-cards' => ['contrast'],
            'editorial-grid' => ['contrast'],
            'featured-ledger' => ['contrast'],
            'featured-stories' => ['contrast'],
        ],
        'trust' => [
            'checklist-band' => ['contrast'], 'classic' => ['contrast'],
            'marker-columns' => ['contrast'], 'numbered-rows' => ['contrast'],
            'brand-manifesto' => ['brand'],
            'ink-ledger' => ['contrast'],
        ],
        'process' => [
            'checklist-steps' => ['contrast'], 'classic' => ['contrast'],
            'marker-columns' => ['contrast'], 'numbered-rows' => ['contrast'],
        ],
        'portfolio_strip' => ['classic' => ['contrast']],
    ];

    /**
     * Families a recipe.variants map may name, per page kind.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_FAMILIES = [
        'service' => ['intro', 'features', 'lead_form', 'team', 'statistics'],
        'about' => ['story', 'values', 'team', 'statistics', 'promo_tiles'],
        'home' => [
            'hero', 'services', 'trust', 'process', 'features',
            'reviews_summary', 'portfolio_strip', 'cta', 'lead_form',
            'team', 'statistics', 'featured_products', 'promo_tiles', 'category_rail',
        ],
        'projects' => ['project_gallery'],
        'project_detail' => [
            'project_detail_hero', 'project_meta_band', 'project_photo_essay',
            'project_about', 'project_cta_row', 'similar_projects',
        ],
    ];

    /**
     * Eyebrow-bearing content families per kind. eyebrow_sections must
     * be a subset of this list (hero is never in it).
     *
     * @var array<string, list<string>>
     */
    private const FILE_BACKED_FAMILIES_BY_KIND = [
        'service' => ['intro', 'features', 'lead_form', 'team', 'statistics'],
        'about' => ['story', 'values', 'team', 'statistics', 'promo_tiles'],
        'home' => ['services', 'trust', 'process', 'portfolio_strip', 'features', 'lead_form', 'team', 'statistics', 'featured_products', 'promo_tiles', 'category_rail'],
        'projects' => ['project_gallery'],
        'project_detail' => [
            'project_detail_hero', 'project_meta_band', 'project_photo_essay',
            'project_cta_row', 'similar_projects',
        ],
    ];

    /**
     * Flat, recipe-global option keys for the lead_form family (spec §Axes).
     *
     * @var array<string, list<string>>
     */
    public const FORM_OPTIONS = [
        'form_input_style' => ['boxed', 'underline', 'soft-filled'],
        'form_surface' => ['card-on-dark', 'panel-inverted', 'flat-cream'],
        'form_trust_style' => ['tick-list', 'chips-under-button', 'inline-piped', 'pill-badges', 'icon-box'],
        'form_radio_style' => ['pills', 'segmented', 'tiles'],
        'form_submit_style' => ['full-width', 'auto-arrow', 'auto'],
    ];

    /**
     * composition ⇒ allowed values per option; a missing key means "any".
     *
     * @var array<string, array<string, list<string>>>
     */
    public const FORM_COMPATIBILITY = [
        'centered' => ['form_input_style' => ['boxed', 'soft-filled'], 'form_surface' => ['flat-cream', 'card-on-dark'], 'form_trust_style' => ['chips-under-button', 'inline-piped']],
        'phone-ledger' => ['form_input_style' => ['boxed', 'underline'], 'form_surface' => ['card-on-dark'], 'form_trust_style' => ['tick-list', 'icon-box']],
        'split-screen' => ['form_input_style' => ['soft-filled', 'boxed'], 'form_surface' => ['card-on-dark'], 'form_trust_style' => ['pill-badges', 'tick-list']],
        'inline-editorial' => ['form_input_style' => ['underline'], 'form_surface' => ['panel-inverted'], 'form_trust_style' => ['inline-piped'], 'form_submit_style' => ['auto-arrow']],
        'image-backed' => ['form_input_style' => ['boxed'], 'form_surface' => ['card-on-dark'], 'form_trust_style' => ['tick-list']],
        'inline-band' => ['form_input_style' => ['boxed', 'soft-filled'], 'form_surface' => ['flat-cream', 'card-on-dark'], 'form_trust_style' => [], 'form_submit_style' => ['auto-arrow']],
        'editorial-ledger' => ['form_input_style' => ['underline'], 'form_surface' => ['flat-cream'], 'form_trust_style' => [], 'form_submit_style' => ['auto', 'auto-arrow', 'full-width']],
    ];

    /**
     * Compositions whose allowed input styles exclude `underline`, which an
     * omitted key could resolve to via sites.form_style.
     *
     * @var list<string>
     */
    public const FORM_EXPLICIT_INPUT_STYLE = ['centered', 'split-screen', 'image-backed', 'inline-editorial', 'inline-band', 'editorial-ledger'];

    /** @var list<string> */
    private const HOME_INSERT_ALLOWLIST = ['portfolio_strip'];

    /** @var array<string, string> */
    private const CONFIG_MAP = [
        'service' => 'site_service_layouts',
        'about' => 'site_about_layouts',
        'home' => 'site_home_layouts',
        'projects' => 'site_projects_layouts',
        'project_detail' => 'site_project_detail_layouts',
        'chrome' => 'site_chrome_layouts',
    ];

    /** @var array<string, string> */
    private const COLUMN_MAP = [
        'service' => 'services_layout',
        'about' => 'about_layout',
        'home' => 'home_layout',
        'chrome' => 'chrome_layout',
        // projects is intentionally absent: sites.projects_layout is the
        // CaseStudies swap. Recipe keys come from resolveProjectsRecipeKey().
        // project_detail is intentionally absent: there is no
        // sites.project_detail_layout column. Recipe keys stay classic
        // unless a page-level layout_preset_key is set.
    ];

    private const SECTION_TYPE_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    private const VARIANT_NAME_PATTERN = '/^[a-z0-9-]{1,16}$/';

    /**
     * Hard-validity: warnings (composition keys) are non-fatal. Missing
     * variant files are reported by validate() but do not make a recipe
     * unusable — the dispatcher already falls back at render time.
     * Wrong-kind and unknown variant families, and eyebrow_sections that
     * name a family the kind cannot eyebrow, are hard errors (fail-closed).
     *
     * @param  array<string, mixed>  $recipe
     */
    public function isUsable(array $recipe, string $kind = 'service'): bool
    {
        return $this->hardErrors($recipe, $kind) === [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(Site $site, string $kind = 'service'): ?array
    {
        return $this->resolveKey($site, $kind, $this->layoutKeyFor($site, $kind));
    }

    /**
     * Page key → site kind column → null.
     *
     * @return array<string, mixed>|null
     */
    public function resolveForPage(Site $site, ?GeneratedPage $page, string $kind): ?array
    {
        $pageKey = is_string($page?->layout_preset_key) && $page->layout_preset_key !== ''
            ? $page->layout_preset_key
            : null;

        // Detail pages inherit the PARENT projects page's per-page override
        // (some sites carry one) before falling to the site key, so
        // detail styling always follows the projects page it hangs off.
        if ($pageKey === null && $kind === 'project_detail' && $page?->parent_id !== null) {
            $parentKey = $page->parent?->layout_preset_key;
            if (is_string($parentKey) && $parentKey !== '') {
                $pageKey = $parentKey;
            }
        }

        return $this->resolveKey($site, $kind, $pageKey ?? $this->layoutKeyFor($site, $kind));
    }

    /**
     * @return array<string, mixed>|null null for classic / unknown / unusable / wrong-site / inactive.
     */
    public function resolveKey(Site $site, string $kind, ?string $key): ?array
    {
        if ($key === null || $key === 'classic') {
            return null;
        }
        if ($site->id) {
            $row = LayoutPreset::query()
                ->where('site_id', $site->id)->where('page_kind', $kind)
                ->where('key', $key)->where('status', LayoutPreset::STATUS_ACTIVE)->first();
            if ($row !== null && is_array($row->recipe)) {
                $hydrated = $this->hydrateFromRow($row);

                return $this->isUsable($hydrated, $kind) ? $this->expandMotionTier($hydrated) : null;
            }
        }
        $config = config($this->configKey($kind, $key));

        return is_array($config) && $this->isUsable($config, $kind) ? $this->expandMotionTier($config) : null;
    }

    public function layoutKindForPage(GeneratedPage $page): ?string
    {
        if (! is_string($page->page_type) || $page->page_type === '') {
            return null;
        }

        // Kind-column arm ahead of page_type heuristics (spec F2): an
        // explicitly-kinded nested detail page must not fall through to
        // isServicePage() (lead-form injection) or return null (pickers).
        if ($page->kind === PageKind::ProjectDetail) {
            return 'project_detail';
        }

        return match (true) {
            $page->page_type === 'home' => 'home',
            $page->page_type === 'about' => 'about',
            $page->page_type === 'projects' => 'projects',
            $page->isServicePage() => 'service',
            default => null,
        };
    }

    /**
     * Recipe-only advisories. Never blocks a picker save; assigner treats
     * a non-empty list as a hard skip.
     *
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    public function recipeWarnings(array $recipe, string $kind): array
    {
        if ($kind === 'chrome') {
            return [];
        }

        $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
        $numbered = array_keys(array_filter($variants, fn ($v) => $v === 'numbered-rows'));

        return $kind === 'home' && count($numbered) > 1
            ? ['numbered-rows is stamped on '.implode(' and ', $numbered).' — two ledgers on one home reads as monotony']
            : [];
    }

    /**
     * Structural treatment = surface + density + item geometry.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    public function adjacencyWarnings(array $sections, array $recipe, string $kind): array
    {
        unset($kind);

        $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
        $surfaces = is_array($recipe['surfaces'] ?? null) ? $recipe['surfaces'] : [];
        $warnings = [];
        $prev = null;

        foreach ($sections as $s) {
            if (! is_array($s) || ! is_string($s['type'] ?? null) || str_starts_with($s['type'], '__')) {
                continue;
            }

            $items = is_array($s['items'] ?? null) ? count($s['items']) : 0;
            $variant = array_key_exists('variant', $s)
                ? $s['variant']
                : ($variants[$s['type']] ?? 'classic');
            if (! is_string($variant) || $variant === '') {
                $variant = 'classic';
            }
            $treatment = [$surfaces[$s['type']] ?? 'base', $items > 4 ? 'long' : 'short', $this->geometryOf($variant)];

            if ($items > 6 && $this->geometryOf($variant) === 'ledger') {
                $warnings[] = "{$s['type']}: single-column ledger with {$items} items — consider grouping";
            }

            if ($prev !== null && $prev['treatment'] === $treatment && $treatment[1] === 'long') {
                $warnings[] = "{$prev['type']} and {$s['type']} are adjacent long sections with the same treatment";
            }

            $prev = ['type' => $s['type'], 'treatment' => $treatment];
        }

        return $warnings;
    }

    private function geometryOf(string $variant): string
    {
        return match (true) {
            in_array($variant, ['numbered-rows', 'featured-ledger', 'ink-ledger'], true) => 'ledger',
            in_array($variant, ['photo-cards', 'editorial-grid', 'cards'], true) => 'grid',
            default => 'other',
        };
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    public function validate(array $recipe, string $kind = 'service'): array
    {
        if ($kind === 'chrome') {
            return $this->hardErrors($recipe, $kind);
        }

        $errors = $this->hardErrors($recipe, $kind);
        $errors = array_merge($errors, $this->missingPartialErrors($recipe, $kind));

        foreach (['section_order', 'omit_sections'] as $key) {
            if ($this->hasNonEmptyComposition($recipe[$key] ?? null)) {
                $errors[] = "Warning: recipe.{$key} is reserved and ignored by the v1 renderer";
            }
        }

        if ($kind === 'home') {
            $errors = array_merge($errors, $this->homeInsertErrors($recipe));
        } elseif ($this->hasNonEmptyComposition($recipe['insert_sections'] ?? null)) {
            $errors[] = 'Warning: recipe.insert_sections is reserved and ignored by the v1 renderer';
        }

        return $errors;
    }

    /**
     * @return array<string, array{label: string, description: string|null}>
     */
    public function optionsFor(Site $site, string $kind = 'service'): array
    {
        $options = [];
        $configName = self::CONFIG_MAP[$kind] ?? null;
        $config = $configName !== null ? config($configName, []) : [];

        if (is_array($config)) {
            foreach ($config as $key => $recipe) {
                if (! is_string($key) || ! is_array($recipe) || ! $this->isUsable($recipe, $kind)) {
                    continue;
                }

                $options[$key] = [
                    'label' => (string) ($recipe['label'] ?? $key),
                    'description' => isset($recipe['description']) ? (string) $recipe['description'] : null,
                ];
            }
        }

        if ($site->id) {
            $rows = LayoutPreset::query()
                ->where('site_id', $site->id)
                ->where('page_kind', $kind)
                ->where('status', LayoutPreset::STATUS_ACTIVE)
                ->orderBy('key')
                ->get();

            foreach ($rows as $row) {
                if (! is_array($row->recipe) || ! $this->isUsable($this->hydrateFromRow($row), $kind)) {
                    unset($options[$row->key]);

                    continue;
                }

                $options[$row->key] = [
                    'label' => $row->label,
                    'description' => $row->description,
                ];
            }
        }

        return $options;
    }

    /**
     * Config-file base name for a kind ('site_home_layouts', ...), null for
     * an unknown kind. Public because the promote command needs to name the
     * file in operator instructions and read the pasted stub back.
     */
    public function configNameFor(string $kind): ?string
    {
        return self::CONFIG_MAP[$kind] ?? null;
    }

    /**
     * Service-page page_type tokens for a site. Empty/null types are omitted
     * so callers never match untagged sections against a null slot.
     *
     * @return list<string>
     */
    public function servicePageTypesFor(Site $site): array
    {
        if (! $site->id) {
            return [];
        }

        return GeneratedPage::query()
            ->where('site_id', $site->id)
            ->get(['page_type', 'kind'])
            ->filter(fn (GeneratedPage $page): bool => $page->isServicePage())
            ->pluck('page_type')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function pageTypesForKind(Site $site, string $kind): array
    {
        return match ($kind) {
            'about' => ['about'],
            'home' => ['home'],
            'projects' => ['projects'],
            'project_detail' => $this->projectDetailPageTypesFor($site),
            default => $this->servicePageTypesFor($site),
        };
    }

    /**
     * Explicitly-kinded project_detail page_type tokens for a site.
     *
     * @return list<string>
     */
    public function projectDetailPageTypesFor(Site $site): array
    {
        if (! $site->id) {
            return [];
        }

        return GeneratedPage::query()
            ->where('site_id', $site->id)
            ->where('kind', PageKind::ProjectDetail)
            ->get(['page_type', 'kind'])
            ->pluck('page_type')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function fileBackedFamiliesFor(string $kind): array
    {
        return self::FILE_BACKED_FAMILIES_BY_KIND[$kind] ?? [];
    }

    /**
     * Variant tokens assigned to a section family by usable stock recipes.
     *
     * @return list<string>
     */
    public function variantOptionsFor(string $pageKind, string $type): array
    {
        $configName = self::CONFIG_MAP[$pageKind] ?? null;
        if ($configName === null) {
            return [];
        }

        $recipes = config($configName, []);
        if (! is_array($recipes)) {
            return [];
        }

        $options = [];
        foreach ($recipes as $recipe) {
            if (! is_array($recipe) || ! $this->isUsable($recipe, $pageKind)) {
                continue;
            }

            $variant = $recipe['variants'][$type] ?? null;
            if (is_string($variant) && $variant !== '' && ! in_array($variant, $options, true)) {
                $options[] = $variant;
            }
        }

        return $options;
    }

    /**
     * Whether the wrapper that will render for this effective variant reads
     * a __surface stamp of the given value. Null/empty means the family's
     * default (classic) arm, which for the extracted families is surface-aware.
     */
    public function variantConsumesSurface(string $sectionType, mixed $variant, string $value): bool
    {
        $effective = (is_string($variant) && $variant !== '') ? $variant : 'classic';

        return in_array($value, self::SURFACE_CONSUMING_VARIANTS[$sectionType][$effective] ?? [], true);
    }

    /**
     * A persisted non-null variant that is absent from the family allowlist
     * and (when file-backed) has no variant file. Explicit null is NOT dead.
     */
    public function isDeadPersistedVariant(string $sectionType, mixed $variant): bool
    {
        if (! is_string($variant) || $variant === '') {
            return false;
        }

        $inline = self::INLINE_VARIANT_FAMILIES[$sectionType] ?? null;
        if (is_array($inline) && in_array($variant, $inline, true)) {
            return false;
        }

        if (in_array($sectionType, self::FILE_BACKED_FAMILIES, true)) {
            if (preg_match(self::VARIANT_NAME_PATTERN, $variant) !== 1) {
                return true;
            }

            return ! view()->exists("site.sections.variants.{$sectionType}.{$variant}");
        }

        // Dead-as-absent is only licensed where absent-vs-dead byte-identity
        // is proven: file-backed families (missing file falls back to classic)
        // and hero (venue characterization). cta echoes its token into the DOM
        // and reviews_summary lets it suppress display_style, so unknown
        // tokens on the other inline families stay explicit (fail-closed).
        return $sectionType === 'hero' && is_array($inline);
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function shouldStampVariant(array $section, string $type): bool
    {
        if (! array_key_exists('variant', $section)) {
            return true;
        }

        if ($section['variant'] === null) {
            return false;
        }

        return $this->isDeadPersistedVariant($type, $section['variant']);
    }

    public function invalidateFor(LayoutPreset $preset): void
    {
        if (! $preset->site_id) {
            return;
        }

        $site = $preset->relationLoaded('site') && $preset->site
            ? $preset->site
            : (new Site)->forceFill(['id' => $preset->site_id]);

        app(PublicPageCache::class)->invalidate($site);
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateFromRow(LayoutPreset $row): array
    {
        $recipe = $row->recipe;
        $recipe['label'] = $row->label;
        $recipe['description'] = $row->description;

        return $recipe;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    public function hardErrors(array $recipe, string $kind = 'service'): array
    {
        if ($kind === 'chrome') {
            return ChromeRecipe::errors($recipe);
        }

        $errors = [];

        if (! array_key_exists('schema_version', $recipe) || ! is_int($recipe['schema_version'])) {
            $errors[] = 'recipe.schema_version must be an integer';
        } elseif ($recipe['schema_version'] !== self::SUPPORTED_SCHEMA_VERSION) {
            $errors[] = 'recipe.schema_version is not a supported schema version';
        }

        $allowed = self::ALLOWED_FAMILIES[$kind] ?? [];

        if (! array_key_exists('variants', $recipe) || ! is_array($recipe['variants'])) {
            $errors[] = 'recipe.variants must be an array';
        } else {
            foreach ($recipe['variants'] as $sectionType => $variantName) {
                if (! is_string($sectionType) || preg_match(self::SECTION_TYPE_PATTERN, $sectionType) !== 1) {
                    $errors[] = "recipe.variants key [{$sectionType}] is not a valid section type (must match /^[a-z0-9_-]{1,32}$/)";
                } elseif (! in_array($sectionType, $allowed, true)) {
                    $errors[] = "recipe.variants key [{$sectionType}] is not an allowed family for kind [{$kind}]";
                }
                if (! is_string($variantName) || preg_match(self::VARIANT_NAME_PATTERN, $variantName) !== 1) {
                    $errors[] = "recipe.variants.{$sectionType} is not a valid variant name (must match /^[a-z0-9-]{1,16}$/)";
                }
            }
        }

        $opts = $recipe['options'] ?? [];
        if (! is_array($opts)) {
            $errors[] = 'recipe.options must be a map';
        } else {
            $known = [
                'drop_cap' => [true, false],
                'gallery_heading' => ['ruled'],
                'detail_heading' => ['ruled'],
                'link_detail_pages' => [true, false],
                'hover_thumbnails' => [true, false],
                'band_image_count' => [1, 2, 3],
                'grid_columns' => [2, 3, 4],
                'grid_numbers' => [true, false],
                'grid_image_corners' => ['square', 'round-top', 'round-bottom', 'round-all'],
                'grid_rows' => [1, 2, 3, 4, 'all'],
                'band_image_height' => ['short', 'standard', 'tall'],
                'image_radius' => ['sharp', 'soft'],
                'image_alignment' => ['left', 'right'],
                'image_alignment_secondary' => ['left', 'right'],
                'side_image' => [true, false],
                ...self::FORM_OPTIONS,
                ...self::MOTION_OPTIONS,
                ...self::ADJACENCY_OPTIONS,
            ];
            foreach ($opts as $optKey => $optVal) {
                if ($optKey === 'featured_count') {
                    // Featured tier size for the featured-* services variants:
                    // first-N presentation logic, never per-item metadata.
                    if (! is_int($optVal) || $optVal < 1 || $optVal > 8) {
                        $errors[] = 'recipe.options.featured_count must be an integer from 1 to 8';
                    }

                    continue;
                }
                if (! array_key_exists($optKey, $known)) {
                    $errors[] = "recipe.options.{$optKey} is not a known option";
                } elseif (! in_array($optVal, $known[$optKey], true)) {
                    $errors[] = "recipe.options.{$optKey} has an invalid value";
                }
            }
            $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
            $formVariant = $variants['lead_form'] ?? null;
            if (is_string($formVariant) && in_array('lead_form', self::ALLOWED_FAMILIES[$kind] ?? [], true)) {
                if (in_array($formVariant, self::FORM_EXPLICIT_INPUT_STYLE, true) && ! array_key_exists('form_input_style', $opts)) {
                    $errors[] = "recipe.options.form_input_style is required for lead_form variant [{$formVariant}]";
                }
                foreach (self::FORM_COMPATIBILITY[$formVariant] ?? [] as $optKey => $allowed) {
                    if (array_key_exists($optKey, $opts) && ! in_array($opts[$optKey], $allowed, true)) {
                        $errors[] = "recipe.options.{$optKey} [{$opts[$optKey]}] is not compatible with lead_form variant [{$formVariant}]";
                    }
                }
            }
        }
        $errors = array_merge($errors, $this->heroModeErrors($recipe, $kind));
        $errors = array_merge($errors, $this->motionTierErrors($recipe));

        $policy = $recipe['eyebrow_policy'] ?? null;
        if ($policy !== 'all' && $policy !== 'first-only') {
            $errors[] = 'recipe.eyebrow_policy must be all or first-only';
        }

        $errors = array_merge($errors, $this->eyebrowSectionErrors($recipe, $kind));
        $errors = array_merge($errors, $this->surfacesErrors($recipe, $kind));

        return $errors;
    }

    /**
     * Absent key == valid; MUST stay valid (stock recipes carry no key).
     *
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function motionTierErrors(array $recipe): array
    {
        if (! array_key_exists('motion_tier', $recipe)) {
            return [];
        }

        return in_array($recipe['motion_tier'], self::MOTION_TIERS, true)
            ? [] : ['recipe.motion_tier must be one of: '.implode(', ', self::MOTION_TIERS)];
    }

    /**
     * Expands motion_tier enum (if present at root or in options) to the
     * per-device option flags. Explicit options override the macro default.
     * Absent motion_tier early returns the recipe untouched.
     *
     * @param  array<string, mixed>  $recipe
     * @return array<string, mixed>
     */
    public function expandMotionTier(array $recipe): array
    {
        $tier = $recipe['options']['motion_tier'] ?? $recipe['motion_tier'] ?? null;
        if (! is_string($tier) || ! isset(self::MOTION_TIER_EXPANSIONS[$tier])) {
            return $recipe;
        }

        $defaults = self::MOTION_TIER_EXPANSIONS[$tier];
        $currentOptions = is_array($recipe['options'] ?? null) ? $recipe['options'] : [];
        $recipe['options'] = array_merge($defaults, $currentOptions);

        return $recipe;
    }

    /**
     * Absent key == force; MUST stay valid (stock recipes carry no key).
     *
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function heroModeErrors(array $recipe, string $kind): array
    {
        if (! array_key_exists('hero_mode', $recipe)) {
            return [];
        }
        if ($kind !== 'home') {
            return ['recipe.hero_mode is only valid for the home kind'];
        }

        return in_array($recipe['hero_mode'], ['force', 'default'], true)
            ? [] : ['recipe.hero_mode must be force or default'];
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function surfacesErrors(array $recipe, string $kind): array
    {
        if (! array_key_exists('surfaces', $recipe)) {
            return [];
        }

        $surfaces = $recipe['surfaces'];
        if ($surfaces === []) {
            return [];
        }

        if ($kind !== 'home') {
            return ['recipe.surfaces is only valid for kind [home]'];
        }

        if (! is_array($surfaces)) {
            return ['recipe.surfaces must be a map of section type => contrast'];
        }

        $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
        $variantKeys = array_keys($variants);
        $errors = [];
        foreach ($surfaces as $sectionType => $value) {
            $label = is_scalar($sectionType) ? (string) $sectionType : gettype($sectionType);
            if (! is_string($sectionType) || ! in_array($sectionType, $variantKeys, true)) {
                $errors[] = "recipe.surfaces key [{$label}] is not a section type this recipe stamps";
            }
            if (! is_string($value) || ! in_array($value, self::SURFACE_VALUES, true)) {
                $valueLabel = is_scalar($value) ? (string) $value : gettype($value);
                $errors[] = "recipe.surfaces.{$label} has an invalid value [{$valueLabel}] (must be one of: contrast, brand)";
            }
            $stamped = is_string($sectionType) ? ($variants[$sectionType] ?? null) : null;
            if (is_string($sectionType) && in_array($sectionType, $variantKeys, true) && is_string($value)
                && (! is_string($stamped) || ! $this->variantConsumesSurface($sectionType, $stamped, $value))) {
                $stampedLabel = is_scalar($stamped) ? (string) $stamped : gettype($stamped);
                $errors[] = "recipe.surfaces.{$label} targets [{$stampedLabel}] which does not consume a [{$value}] surface stamp";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function eyebrowSectionErrors(array $recipe, string $kind): array
    {
        if (! array_key_exists('eyebrow_sections', $recipe)) {
            return [];
        }

        $sections = $recipe['eyebrow_sections'];
        if (! is_array($sections)) {
            return ['recipe.eyebrow_sections must be an array of section types'];
        }

        $allowed = self::FILE_BACKED_FAMILIES_BY_KIND[$kind] ?? [];
        $errors = [];
        foreach ($sections as $sectionType) {
            $label = is_scalar($sectionType) ? (string) $sectionType : gettype($sectionType);
            if (! is_string($sectionType) || ! in_array($sectionType, $allowed, true)) {
                $errors[] = "recipe.eyebrow_sections names [{$label}] which is not a content family for kind [{$kind}]";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function missingPartialErrors(array $recipe, string $kind = 'service'): array
    {
        if (! is_array($recipe['variants'] ?? null)) {
            return [];
        }

        $allowed = self::ALLOWED_FAMILIES[$kind] ?? [];
        $errors = [];
        foreach ($recipe['variants'] as $sectionType => $variantName) {
            if (! is_string($sectionType) || ! is_string($variantName)) {
                continue;
            }
            if (preg_match(self::SECTION_TYPE_PATTERN, $sectionType) !== 1
                || preg_match(self::VARIANT_NAME_PATTERN, $variantName) !== 1) {
                continue;
            }

            if (! in_array($sectionType, $allowed, true)) {
                continue;
            }

            if (in_array($sectionType, self::FILE_BACKED_FAMILIES, true)) {
                $view = "site.sections.variants.{$sectionType}.{$variantName}";
                if (! view()->exists($view)) {
                    $errors[] = "recipe.variants.{$sectionType} names a missing partial ({$view})";
                }

                continue;
            }

            if (isset(self::INLINE_VARIANT_FAMILIES[$sectionType])
                && ! in_array($variantName, self::INLINE_VARIANT_FAMILIES[$sectionType], true)
            ) {
                $errors[] = "recipe.variants.{$sectionType} names unknown inline token ({$variantName})";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function homeInsertErrors(array $recipe): array
    {
        $inserts = $recipe['insert_sections'] ?? null;
        if (! $this->hasNonEmptyComposition($inserts)) {
            return [];
        }

        if (! is_array($inserts)) {
            return ['recipe.insert_sections must be an array of known insert types'];
        }

        $errors = [];
        foreach ($inserts as $type) {
            $label = is_scalar($type) ? (string) $type : gettype($type);
            if (! is_string($type) || ! in_array($type, self::HOME_INSERT_ALLOWLIST, true)) {
                $errors[] = "recipe.insert_sections names unknown insert [{$label}]";
            }
        }

        return $errors;
    }

    private function hasNonEmptyComposition(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Projects recipes follow the services personality. sites.projects_layout
     * is the CaseStudies swap and is not read here.
     */
    public function resolveProjectsRecipeKey(Site $site): string
    {
        $key = $site->services_layout ?? 'classic';
        if ($key instanceof BackedEnum) {
            $key = $key->value;
        }

        return is_string($key) && $key !== '' ? $key : 'classic';
    }

    private function layoutKeyFor(Site $site, string $kind): ?string
    {
        if ($kind === 'projects' || $kind === 'project_detail') {
            // project_detail follows the projects personality (which itself
            // follows services_layout); a missing personality recipe in the
            // detail config falls back to classic via resolveKey().
            return $this->resolveProjectsRecipeKey($site);
        }

        $column = self::COLUMN_MAP[$kind] ?? null;
        if ($column === null) {
            return null;
        }

        $key = $site->{$column} ?? 'classic';
        if ($key instanceof BackedEnum) {
            $key = $key->value;
        }

        return is_string($key) && $key !== '' ? $key : 'classic';
    }

    private function configKey(string $kind, string $key): string
    {
        $file = self::CONFIG_MAP[$kind] ?? 'site_service_layouts';

        return "{$file}.{$key}";
    }
}
