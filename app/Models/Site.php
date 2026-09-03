<?php

namespace App\Models;

use App\Enums\GenerationMode;
use App\Enums\HeroVideoLoop;
use App\Enums\ImageQualityTier;
use App\Enums\LogoSize;
use App\Enums\PreviewLayout;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Enums\ProjectsLayout;
use App\Enums\ReviewsHeroBadgeMode;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Enums\SitePurpose;
use App\Enums\SiteStatus;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Services\Site\SiteHostResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Snapshot of deleted_at captured by `restoring` so the `restored`
     * hook can match cascade-deleted children. Not persisted — declared
     * as a real property so Eloquent treats it as instance state instead
     * of routing through setAttribute() and trying to write a column.
     */
    public ?CarbonInterface $cascadeDeletedAtSnapshot = null;

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            if (! $site->preview_brand) {
                $site->preview_brand = 'a';
            }

            if (! $site->preview_domain && $site->business_name) {
                $brand = $site->preview_brand;
                $site->preview_domain = app(SiteHostResolver::class)
                    ->allocateSlug(
                        $site->business_name,
                        fn (string $candidate) => static::where('preview_brand', $brand)
                            ->where('preview_domain', $candidate)
                            ->exists(),
                    );
            }
        });

        // Cascade soft-delete to every Site child by site_id. We query the
        // child models directly rather than via the relation accessors —
        // businessProfile() uses latestOfMany() which would only delete the
        // latest row and orphan the rest, and we want every child row to
        // track the parent's deleted_at. Skipped on force-delete because
        // FK ON DELETE CASCADE already handles the hard-delete subtree.
        //
        // Stamps every newly-trashed child with the parent's exact
        // deleted_at value so cascade-restore can identify them later
        // without disturbing children that were soft-deleted independently.
        // We exclude already-trashed rows (whereNull('deleted_at')) so an
        // independently-trashed child keeps its original timestamp; only
        // its current deleted_at value identifies it as "not part of this
        // cascade", which restored() then leaves alone.
        static::deleted(function (Site $site): void {
            if ($site->isForceDeleting()) {
                return;
            }
            $cascadeStamp = $site->deleted_at;
            foreach (self::CHILD_MODELS as $modelClass) {
                $modelClass::where('site_id', $site->id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $cascadeStamp]);
            }
        });

        // Cascade restore. Restores only children whose deleted_at matches
        // the parent's pre-restore timestamp — i.e. those marked by the
        // parent's deleted() handler. Children that were soft-deleted
        // independently (different timestamp) are left trashed.
        // The pre-restore deleted_at must be captured in `restoring`
        // because `restored` runs after deleted_at has been NULLed.
        static::restoring(function (Site $site): void {
            $site->cascadeDeletedAtSnapshot = $site->deleted_at;
        });

        static::restored(function (Site $site): void {
            $stamp = $site->cascadeDeletedAtSnapshot;
            $site->cascadeDeletedAtSnapshot = null;
            if ($stamp === null) {
                return;
            }
            foreach (self::CHILD_MODELS as $modelClass) {
                $modelClass::onlyTrashed()
                    ->where('site_id', $site->id)
                    ->where('deleted_at', $stamp)
                    ->restore();
            }
        });
    }

    /**
     * @var list<class-string<Model>> Child models scoped by site_id whose
     *                                soft-delete state tracks the parent
     *                                Site. SitePersonalisationFace already
     *                                had soft deletes before this batch.
     *
     * SiteMedia is intentionally absent: it now has SoftDeletes for
     * library deletes, but it still survives a parent site soft-delete
     * so a restore can re-use uploaded assets without re-uploading.
     * Hard-delete still removes its rows via the FK's ON DELETE CASCADE.
     */
    private const CHILD_MODELS = [
        BeforeAfterPair::class,
        BusinessProfile::class,
        GeneratedPage::class,
        HeroVersion::class,
        ImportedMedia::class,
        LogoConcept::class,
        Preview::class,
        ProjectItem::class,
        Site\SiteDraft::class,
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    protected $fillable = [
        'client_id',
        'created_by_user_id', 'assigned_to_user_id',
        'business_name', 'slug', 'business_type', 'site_type', 'location', 'region', 'country',
        'agent_notes',
        'generation_mode', 'status', 'purpose', 'theme', 'design_brief', 'admin_suggestions', 'preview_layout', 'last_error',
        'brand_favicon_url', 'brand_og_url', 'brand_og_square_url', 'brand_og_custom_path', 'brand_og_custom_meta',
        'preview_domain', 'preview_brand',
        'custom_domain', 'custom_domain_status', 'custom_domain_cf_id', 'custom_domain_cf_zone',
        'projects_page_enabled',
        'projects_layout',
        'home_layout',
        'chrome_layout',
        'brand_image_path',
        'brand_image_opacity',
        'brand_image_fit',
        'brand_image_position_y',
        'brand_image_media_id',
        'services_layout',
        'about_layout',
        'logo_size',
        'header_bg',
        'nav_row_bg',
        'nav_row_pattern',
        'nav_row_image_path',
        'nav_row_image_opacity',
        'nav_row_image_fit',
        'nav_row_image_position_y',
        'nav_row_image_media_id',
        'nav_row_accent_border',
        'shop_noun',
        'texture_key',
        'texture_opacity',
        'texture_image_path',
        'announcement_enabled',
        'announcement_messages',
        'announcement_bg',
        'logo_margin',
        'overlay_logo_concept_id',
        'overlay_logo_size',
        'overlay_inner_scale',
        'overlay_glass',
        'overlay_logo_margin',
        'nav_case',
        'nav_container_style',
        'nav_container_fill',
        'header_padding',
        'header_shrink',
        'header_fit',
        'header_mode',
        'right_action',
        'nav_cta_label',
        'nav_cta_url',
        'nav_cta_target',
        'footer_show_logo',
        'footer_motto',
        'form_style',
        'accent_style',
        'honest_project_framing',
        'project_categories',
        'image_quality_tier',
        'personalise_enabled',
        'home_hero_video_enabled',
        'review_provider',
        'review_place_id',
        'review_source_url',
        'review_place_id_source',
        'reviews_cache',
        'reviews_cache_fetched_at',
        'reviews_cache_status',
        'reviews_cache_error',
        'review_suppressions',
        'native_reviews_enabled',
        'enquiry_notification_email',
        'reviews_show_count_in_hero',
        'reviews_hero_badge_mode',
        'reviews_show_count_in_summary',
        'reviews_show_date_in_summary',
        'home_hero_video_provider',
        'home_hero_video_tier',
        'home_hero_video_prompt',
        'home_hero_video_path',
        'home_hero_video_loop',
        'home_hero_video_poster_path',
        'home_hero_video_status',
        'home_hero_video_last_generated_at',
        'home_hero_scene',
        'home_hero_scene_draft',
        'hero_focus',
        'hero_copy_style',
        'paid_at',
        'shop_first_purchasable_at',
        'shop_mode',
        'shop_currency',
        'shop_enabled',
        'shop_nav_style',
        'shop_page_size',
        'shop_default_sort',
        'product_tags',
        'auto_tags',
        'shop_index_blocks',
        'product_fact_groups',
        'reviews_settings',
        'fulfilment',
        'default_customer_inputs',
    ];

    // Mirrors the DB default so a model that hasn't round-tripped yet
    // (factory create, fresh Site::create) still reads as a website.
    protected $attributes = [
        'purpose' => 'website',
        'shop_mode' => 'cart',
        'shop_currency' => 'GBP',
        'shop_enabled' => false,
        'chrome_layout' => 'classic',
        'brand_image_opacity' => 12,
        'brand_image_fit' => 'cover',
        'nav_row_image_opacity' => 12,
        'nav_row_image_fit' => 'cover',
        'announcement_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'purpose' => SitePurpose::class,
            'paid_at' => 'datetime',
            'shop_first_purchasable_at' => 'datetime',
            'shop_enabled' => 'boolean',
            'shop_page_size' => 'integer',
            'fulfilment' => 'array',
            'default_customer_inputs' => 'array',
            'generation_mode' => GenerationMode::class,
            'design_brief' => 'array',
            'admin_suggestions' => 'array',
            'preview_layout' => PreviewLayout::class,
            'image_quality_tier' => ImageQualityTier::class,
            'projects_page_enabled' => 'boolean',
            'projects_layout' => ProjectsLayout::class,
            'home_hero_video_loop' => HeroVideoLoop::class,
            'logo_size' => LogoSize::class,
            'overlay_logo_size' => LogoSize::class,
            'brand_image_opacity' => 'integer',
            'nav_row_image_opacity' => 'integer',
            'texture_opacity' => 'float',
            'announcement_enabled' => 'boolean',
            'announcement_messages' => 'array',
            'footer_show_logo' => 'boolean',
            'honest_project_framing' => 'boolean',
            'project_categories' => 'array',
            'personalise_enabled' => 'boolean',
            'home_hero_video_enabled' => 'boolean',
            'reviews_cache' => 'array',
            'reviews_cache_fetched_at' => 'datetime',
            'review_suppressions' => 'array',
            'native_reviews_enabled' => 'boolean',
            'reviews_show_count_in_hero' => 'boolean',
            'reviews_hero_badge_mode' => ReviewsHeroBadgeMode::class,
            'reviews_show_count_in_summary' => 'boolean',
            'reviews_show_date_in_summary' => 'boolean',
            'home_hero_video_last_generated_at' => 'datetime',
            'home_hero_scene' => 'array',
            'home_hero_scene_draft' => 'array',
            'product_tags' => 'array',
            'auto_tags' => 'array',
            'shop_index_blocks' => 'array',
            'product_fact_groups' => 'array',
            'reviews_settings' => 'array',
            'brand_og_custom_meta' => 'array',
        ];
    }

    public function siteTypeLabel(): ?string
    {
        if ($this->site_type === null || $this->site_type === '') {
            return null;
        }

        $label = config('site_types.'.$this->site_type);

        return is_string($label) ? $label : null;
    }

    public function regionLabel(): ?string
    {
        if ($this->region === null || $this->region === '') {
            return null;
        }

        $label = config('regions.'.$this->region);

        return is_string($label) ? $label : null;
    }

    public function nativeReviews(): HasMany
    {
        return $this->hasMany(SiteReview::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function layoutPresets(): HasMany
    {
        return $this->hasMany(LayoutPreset::class);
    }

    public function heroVideoVersions(): HasMany
    {
        return $this->hasMany(HeroVideoVersion::class)->orderByDesc('created_at');
    }

    public function activeHeroVideoVersion(): ?HeroVideoVersion
    {
        return $this->heroVideoVersions()->where('is_active', true)->first();
    }

    /**
     * Fully-qualified preview hostname under our branded zone, or null if the
     * site has no preview_domain allocated yet.
     */
    public function previewHostname(): ?string
    {
        if (! $this->preview_domain) {
            return null;
        }
        // Hosted demo: the seeded site's preview_domain is already the full public host.
        if (config('demo.enabled') && $this->preview_domain === config('demo.site_host')) {
            return $this->preview_domain;
        }

        return app(SiteHostResolver::class)
            ->previewFqdn($this->preview_domain, $this->preview_brand ?? 'a');
    }

    /**
     * Public hostname visitors hit: an active custom domain, else the
     * branded preview FQDN. Same choice as the agent "View site" link.
     */
    public function publicHost(): ?string
    {
        if ($this->custom_domain && $this->custom_domain_status === 'active') {
            return $this->custom_domain;
        }

        return $this->previewHostname();
    }

    /**
     * Absolute live URL for a page on this site's public host.
     */
    public function publicPageUrl(GeneratedPage $page): ?string
    {
        $host = $this->publicHost();
        if ($host === null) {
            return null;
        }

        $slug = (string) $page->page_type;
        $path = $slug === '' || $slug === 'home' ? '/' : '/'.$slug;

        return 'https://'.$host.$path;
    }

    /**
     * Public URL of the server-generated favicon PNG on Spaces, or null
     * if BrandImageService has not run (or failed) for this site.
     */
    public function faviconUrl(): ?string
    {
        $url = $this->brand_favicon_url ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Public URL of the Open Graph social card. A custom upload wins over
     * the generated PNG so Design → Brand can pin a one-off card.
     */
    /** True when the share image is the merchant's custom upload (which replaces the generated cards). */
    public function ogImageIsCustom(): bool
    {
        $custom = $this->brand_og_custom_path ?? null;

        return is_string($custom) && $custom !== '';
    }

    public function ogImageUrl(): ?string
    {
        $custom = $this->brand_og_custom_path ?? null;
        if (is_string($custom) && $custom !== '') {
            $customUrl = \App\Support\Site\SitePublicObject::url($custom);
            if (is_string($customUrl) && $customUrl !== '') {
                return $customUrl;
            }
        }

        $url = $this->brand_og_url ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Square (1200×1200) companion card for platforms that crop.
     */
    public function ogImageSquareUrl(): ?string
    {
        $url = $this->brand_og_square_url ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Declared og:image width/height for the URL returned by ogImageUrl().
     * Custom uploads use the stored decoded size; generated cards are 1200×630.
     *
     * @return array{width: int|null, height: int|null}
     */
    public function ogImageCardDimensions(): array
    {
        $custom = $this->brand_og_custom_path ?? null;
        if (is_string($custom) && $custom !== '') {
            $meta = is_array($this->brand_og_custom_meta) ? $this->brand_og_custom_meta : [];
            $width = is_numeric($meta['width'] ?? null) ? (int) $meta['width'] : null;
            $height = is_numeric($meta['height'] ?? null) ? (int) $meta['height'] : null;

            return [
                'width' => ($width !== null && $width > 0) ? $width : null,
                'height' => ($height !== null && $height > 0) ? $height : null,
            ];
        }

        $url = $this->brand_og_url ?? null;
        if (is_string($url) && $url !== '') {
            return ['width' => 1200, 'height' => 630];
        }

        return ['width' => null, 'height' => null];
    }

    /**
     * True if this site belongs to a paying customer rather than being a
     * speculative preview.
     *
     * Independent of image_quality_tier, which encodes the same distinction
     * for hero generation only and is set separately by staff — an explicit
     * product decision, not an oversight.
     */
    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** @param  Builder<Site>  $query */
    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_at');
    }

    /** @param  Builder<Site>  $query */
    public function scopeUnpaid($query)
    {
        return $query->whereNull('paid_at');
    }

    /**
     * Resolve the effective "honest project framing" flag for this site.
     * Per-site override wins; otherwise falls through to the global
     * `config('site.honest_project_framing')` default.
     */
    public function effectiveHonestFraming(): bool
    {
        if ($this->honest_project_framing !== null) {
            return (bool) $this->honest_project_framing;
        }

        return (bool) config('site.honest_project_framing', false);
    }

    public function externalApiCalls(): HasMany
    {
        return $this->hasMany(ExternalApiCall::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class)->latestOfMany();
    }

    public function generatedPages(): HasMany
    {
        return $this->hasMany(GeneratedPage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(Preview::class);
    }

    public function latestPreview(): HasOne
    {
        return $this->hasOne(Preview::class)->latestOfMany();
    }

    public function siteDraft(): HasOne
    {
        return $this->hasOne(Site\SiteDraft::class);
    }

    public function logoConcepts(): HasMany
    {
        return $this->hasMany(LogoConcept::class);
    }

    public function overlayLogoConcept(): BelongsTo
    {
        return $this->belongsTo(LogoConcept::class, 'overlay_logo_concept_id');
    }

    public function brandImageMedia(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'brand_image_media_id');
    }

    public function navRowImageMedia(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'nav_row_image_media_id');
    }

    public function heroVersions(): HasMany
    {
        return $this->hasMany(HeroVersion::class);
    }

    public function importedMedia(): HasMany
    {
        return $this->hasMany(ImportedMedia::class);
    }

    public function projectItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class);
    }

    public function projectCategories(): HasMany
    {
        return $this->hasMany(ProjectCategory::class);
    }

    public function galleryItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class)
            ->where('type', ProjectItemType::Gallery->value)
            ->where('status', '!=', ProjectItemStatus::Archived->value)
            ->orderBy('sort_order');
    }

    public function caseStudyItems(): HasMany
    {
        return $this->hasMany(ProjectItem::class)
            ->where('type', ProjectItemType::CaseStudy->value)
            ->where('status', '!=', ProjectItemStatus::Archived->value)
            ->orderBy('sort_order');
    }

    public function selectedLogoConcept(): HasOne
    {
        return $this->hasOne(LogoConcept::class)->where('is_selected', true);
    }

    public function currentShopSnapshot(): HasOne
    {
        return $this->hasOne(ShopSnapshotCurrent::class);
    }

    /**
     * Per-site inert-before-dev switch. Production default is false so the
     * commerce surface is off until an admin or manager opts a site in.
     */
    public function shopEnabled(): bool
    {
        return (bool) $this->shop_enabled;
    }

    /**
     * Fiscal country for tax + checkout. The currency is authoritative; `sites.country`
     * is a content-generation label (CountryResolver prose) and is only trusted when it
     * agrees with the currency, so a GBP store tagged "Australia" still quotes GB VAT.
     */
    public function shopCountryCode(): string
    {
        $currency = strtoupper((string) ($this->shop_currency ?? 'GBP'));
        $byCurrency = self::CURRENCY_COUNTRIES[$currency] ?? ['GB'];

        $raw = is_string($this->country) ? trim($this->country) : '';
        $mapped = $raw !== '' ? self::mapShopCountryToIso2($raw) : null;
        if ($mapped !== null && in_array($mapped, $byCurrency, true)) {
            return $mapped;
        }

        return $byCurrency[0];
    }

    /** Countries a shop currency may legitimately bill in (first = default). */
    private const CURRENCY_COUNTRIES = [
        'GBP' => ['GB'],
        'USD' => ['US'],
        'AUD' => ['AU'],
        'NZD' => ['NZ'],
        'EUR' => ['IE'],
    ];

    private static function mapShopCountryToIso2(string $raw): ?string
    {
        if (strlen($raw) === 2 && ctype_alpha($raw)) {
            $code = strtoupper($raw);

            return $code === 'UK' ? 'GB' : $code;
        }

        return match (strtolower($raw)) {
            'united kingdom', 'great britain', 'uk', 'gb' => 'GB',
            'united states', 'united states of america', 'usa', 'us' => 'US',
            'australia', 'au' => 'AU',
            'new zealand', 'nz' => 'NZ',
            'ireland', 'republic of ireland', 'ie' => 'IE',
            default => null,
        };
    }

    public static function shopEnabledFor(int $siteId): bool
    {
        return (bool) static::query()->whereKey($siteId)->value('shop_enabled');
    }

    /**
     * Whether this site has something a member of the public can actually buy.
     *
     * Gates BROWSE surfaces (the storefront, cart, checkout) and the shop chrome.
     * NOT "has a ShopSnapshotCurrent row": a site can hold a snapshot with no products.
     *
     * Published-only, and that has to hold on EVERY branch: the snapshot counts Draft
     * AND Published rows, so trusting product_count alone would flip a draft-only site
     * to true and render shop chrome over an empty storefront.
     */
    public function hasPurchasableShop(): bool
    {
        if (! $this->shopEnabled()) {
            return false;
        }

        if (Product::query()
            ->where('site_id', $this->id)
            ->where('status', ProductStatus::Published)
            ->exists()) {
            return true;
        }

        // Fall back to the snapshot the storefront would actually render, counting only
        // products it would show the public. product_count is NOT usable here: it is
        // draft-inclusive by construction.
        $json = ShopSnapshot::query()
            ->join('shop_snapshot_current', 'shop_snapshot_current.snapshot_id', '=', 'shop_snapshots.id')
            ->where('shop_snapshot_current.site_id', $this->id)
            ->value('shop_snapshots.json');

        if ($json === null) {
            return false;
        }
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        foreach ($json['products'] ?? [] as $product) {
            if (($product['status'] ?? 'published') === 'published') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this site IS a shop, durably — regardless of whether anything is on sale
     * right now.
     *
     * Gates the surfaces an existing customer is owed after they have paid: checkout
     * outcome, account, order history, magic-link claim, data export. Sellability is the
     * wrong authority for those. A merchant who archives their last product would
     * otherwise 404 the return URL of a shopper who has just been charged, and lock every
     * existing customer out of their own order history. Regression guard.
     *
     * The shop_enabled flag gates only the purchasable half. Sites that have taken
     * orders keep these owed surfaces reachable after disablement so a mid-checkout
     * charge cannot lock the customer out. Browse/cart/checkout stay dark via
     * hasPurchasableShop().
     */
    /**
     * Client-portal shop admin pages. The platform hides them until the shop is
     * established (something to sell or an order). The public demo starts with an
     * empty catalogue on purpose — an agent populates it through the WebMCP tools —
     * so in demo mode an enabled shop is reachable while still empty. The public
     * storefront gating (ShopDomainResolver) is untouched.
     */
    public function portalShopReachable(): bool
    {
        return $this->shopEnabled() && ($this->hasEstablishedShop() || (bool) config('demo.enabled', false));
    }

    public function hasEstablishedShop(): bool
    {
        // Purchasable (flag on AND something to sell) OR any historical order.
        // Deliberately NOT "has a ShopSnapshotCurrent row": that is the predicate
        // this class already calls meaningless, because the initial reconcile gave
        // one to every site. Including it would expose the customer-account
        // routes on sites that have no shop.
        return ($this->shopEnabled() && $this->hasPurchasableShop())
            || Order::query()->where('site_id', $this->id)->exists();
    }

    /**
     * cart | enquire | quote. Unknown values fall back to cart so a typo
     * cannot darken chrome or open checkout on a quote shop.
     */
    public function shopMode(): string
    {
        return match ($this->shop_mode) {
            'enquire', 'quote' => $this->shop_mode,
            default => 'cart',
        };
    }

    public function shopUsesCartChrome(): bool
    {
        return in_array($this->shopMode(), ['cart', 'quote'], true);
    }

    public function shopAcceptsCheckout(): bool
    {
        return $this->shopMode() === 'cart';
    }

    public function shopShowsEnquiries(): bool
    {
        return in_array($this->shopMode(), ['enquire', 'quote'], true);
    }

    public function shopReservesStock(): bool
    {
        return $this->shopMode() === 'cart';
    }

    /**
     * Live unpaid Stripe sessions: pending rows that have not expired.
     * Same predicate as the shop_enabled disable toggle.
     */
    public function unexpiredPendingOrderCount(): int
    {
        return Order::query()
            ->where('site_id', $this->id)
            ->where('status', OrderStatus::Pending)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
    }

    public function hasTakenOrders(): bool
    {
        return Order::query()->where('site_id', $this->id)->exists();
    }

    /**
     * Order history / fulfilment surfaces a customer is owed after paying,
     * regardless of the current acquisition mode.
     */
    public function shopShowsAccountOrders(): bool
    {
        return $this->shopAcceptsCheckout() || $this->hasTakenOrders();
    }

    /**
     * Apply a shop mode. Refuses cart→quote and cart→enquire while unexpired
     * pending orders exist — same query and message shape as disabling the shop.
     *
     * @return int pending count blocking the change; 0 if the write was applied
     */
    public function tryChangeShopMode(string $mode): int
    {
        $mode = match ($mode) {
            'enquire', 'quote' => $mode,
            default => 'cart',
        };

        $leavingCart = $this->shopMode() === 'cart' && in_array($mode, ['enquire', 'quote'], true);
        if ($leavingCart) {
            $pending = $this->unexpiredPendingOrderCount();
            if ($pending > 0) {
                return $pending;
            }
        }

        $this->update(['shop_mode' => $mode]);

        return 0;
    }

    /**
     * Per-site honeypot field name (8 hex chars of HMAC(site id, app key)).
     */
    public function enquiryHoneypotFieldName(): string
    {
        return substr(hash_hmac('sha256', (string) $this->id, (string) config('app.key')), 0, 8);
    }

    public function managedContentSubscription(): HasOne
    {
        return $this->hasOne(SiteSubscription::class)
            ->where('product', SiteSubscription::PRODUCT_MANAGED_CONTENT);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(Site\SiteVersionCurrent::class);
    }

    /**
     * Sites with an active managed-content subscription, multi-page layout,
     * and a current published version. Used by the monthly fan-out so we
     * never load the whole sites table.
     */
    /**
     * Sites the product machinery may touch — schedulers and sweeps
     * must not act on video-only rows (VideoWorks feed sites).
     */
    public function scopePurposeWebsite(Builder $query): Builder
    {
        return $query->where('purpose', SitePurpose::Website->value);
    }

    public function scopeManagedContentEligible(Builder $query): Builder
    {
        return $query
            ->purposeWebsite()
            ->where('preview_layout', PreviewLayout::MultiPage)
            ->whereHas('currentVersion')
            ->whereHas('managedContentSubscription', fn (Builder $subscription) => $subscription->where('active', true));
    }

    public function isManagedContentEligible(): bool
    {
        if ($this->purpose !== SitePurpose::Website) {
            return false;
        }

        $subscription = $this->managedContentSubscription;

        if ($subscription === null || ! $subscription->active) {
            return false;
        }

        if ($this->preview_layout !== PreviewLayout::MultiPage) {
            return false;
        }

        if ($this->relationLoaded('currentVersion')) {
            return $this->currentVersion !== null;
        }

        return $this->currentVersion()->exists();
    }
}
