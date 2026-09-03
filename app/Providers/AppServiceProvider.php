<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAgentRole;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Livewire\Hooks\BindSnapshotToHost;
use App\Livewire\Hooks\EnforceRoleGuards;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Shop\Category;
use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductReview;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ProductVariantImage;
use App\Models\Site;
use App\Models\SiteReview;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Observers\GeneratedPageObserver;
use App\Observers\PageRevisionObserver;
use App\Observers\ProjectItemObserver;
use App\Observers\Shop\CatalogObserver;
use App\Observers\Shop\ProductReviewObserver;
use App\Observers\SiteObserver;
use App\Observers\SiteReviewObserver;
use App\Services\Shop\RefundService;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperationLogRepository;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EloquentEditorOperationLogRepository;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\RevisionScopes;
use App\Services\Site\Editor\Shop\ShopWriteOperation;
use App\Services\Site\Editor\WarningCodes;
use App\Services\Site\SectionSchema;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RefundService::class, function ($app) {
            $stripe = new StripeClient(config('services.stripe.secret_key'));
            $gateway = new class($stripe)
            {
                public function __construct(private StripeClient $client) {}

                public function refund(string $paymentIntentId, int $amountCents, ?string $idempotencyKey = null): void
                {
                    // The idempotency key makes a retried refund a no-op at Stripe rather
                    // than a second movement of money — the DB transaction around this call
                    // cannot roll back a completed charge reversal.
                    $this->client->refunds->create(
                        ['payment_intent' => $paymentIntentId, 'amount' => $amountCents],
                        $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : []
                    );
                }
            };

            return new RefundService($gateway);
        });

        $this->app->singleton(SectionSchema::class, function () {
            return new SectionSchema(config('site_sections', []));
        });

        // MUST be registered here, not in boot(). ComponentHookRegistry::boot()
        // iterates the hooks registered AT THAT MOMENT and wires each one an
        // on('mount')/on('hydrate') listener; a hook added afterwards sits in the
        // list but is never attached to a component, so its call() never fires and
        // the guard silently does nothing.
        Livewire::componentHook(EnforceRoleGuards::class);
        Livewire::componentHook(BindSnapshotToHost::class);

        $this->app->singleton(
            OperationRegistry::class,
            fn () => OperationRegistry::discover(),
        );

        $this->app->bind(
            EditorOperationLogRepository::class,
            EloquentEditorOperationLogRepository::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRevisionScopes();
        $this->registerWarningCodes();

        // Every environment serves exclusively through Cloudflare over
        // HTTPS; the app itself only ever sees plain HTTP from the tunnel.
        // Deriving the scheme from proxy headers proved brittle (mixed-
        // content asset URLs on public sites), so URL generation is forced
        // to https outright. Disabled in tests via phpunit.xml so simulated
        // requests keep their natural scheme.
        if (config('demo.enabled')) {
            // Console and queue work have no request to bind to, so they use
            // APP_URL. Requests rebind the root to their own host in
            // DemoPublicRequestUrl: the storefront and the portal are different
            // hosts, and each page's links must stay on the host that served it.
            $root = rtrim((string) config('app.url'), '/');
            if ($root !== '') {
                URL::forceRootUrl($root);
            }
            $scheme = parse_url($root, PHP_URL_SCHEME);
            if (is_string($scheme) && $scheme !== '') {
                URL::forceScheme($scheme);
            }
        } elseif (config('app.force_https', true)) {
            URL::forceScheme('https');
        }

        if (config('demo.enabled')) {
            $stub = \App\Livewire\DemoMissingComponent::class;
            foreach ([
                'managed-content-settings',
                'managed-content-approval',
                'home-hero-video-studio',
                'manual-logo-generator',
                'agents.global-search',
                'agents.client-team',
            ] as $name) {
                \Livewire\Livewire::component($name, $stub);
            }
        }

        $this->configureDefaults();
        $this->extendTrustedProxiesFromEnv();
        $this->configureMcpRateLimiter();
        $this->configureSiteReviewsRateLimiter();
        $this->configureCspReportRateLimiter();
        $this->configureLivewirePersistentMiddleware();
        $this->configureCspNonceDirective();
        $this->registerObservers();
    }

    protected function registerObservers(): void
    {
        Site::observe(SiteObserver::class);
        SiteReview::observe(SiteReviewObserver::class);
        GeneratedPage::observe(GeneratedPageObserver::class);
        PageRevision::observe(PageRevisionObserver::class);
        ProjectItem::observe(ProjectItemObserver::class);
        Product::observe(CatalogObserver::class);
        ProductVariant::observe(CatalogObserver::class);
        ProductImage::observe(CatalogObserver::class);
        ProductVariantImage::observe(CatalogObserver::class);
        Category::observe(CatalogObserver::class);
        FeaturedProduct::observe(CatalogObserver::class);
        ProductReview::observe(ProductReviewObserver::class);
    }

    /** Per-actor and per-/64 ceilings on the MCP HTTP surface (session-authenticated callers only). */
    protected function configureMcpRateLimiter(): void
    {
        RateLimiter::for('mcp', function (Request $request) {
            $ipKey = 'mcp-ip:'.$request->ip();
            $actorId = $request->user()?->getAuthIdentifier();
            $actorKey = $actorId !== null ? 'mcp-actor:'.$actorId : $ipKey;

            return [
                Limit::perMinute(120)->by($actorKey),
                Limit::perMinute(60)->by('mcp-ip-ceiling:'.$this->rateLimitIp((string) $request->ip())),
            ];
        });
    }

    /**
     * Native review submissions are rare human events — tight per-IP cap
     * plus a per-host cap so one site can't be flooded from many IPs.
     */
    protected function configureSiteReviewsRateLimiter(): void
    {
        RateLimiter::for('site-reviews', function (Request $request) {
            return [
                Limit::perMinute(3)->by('review-ip:'.$request->ip()),
                Limit::perMinute(15)->by('review-site:'.sha1($request->getHost())),
            ];
        });

        RateLimiter::for('site-enquiries', function (Request $request) {
            return [
                Limit::perMinute(5)->by('enquiry-ip:'.$request->ip()),
                Limit::perMinute(30)->by('enquiry-site:'.sha1($request->getHost())),
            ];
        });

        RateLimiter::for('site-quote-requests', function (Request $request) {
            $site = $request->attributes->get('resolved_site');
            $siteKey = $site instanceof Site
                ? (string) $site->id
                : sha1($request->getHost());
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('enquiry-ip:'.$ip),
                Limit::perMinute(30)->by('enquiry-site:'.sha1($request->getHost())),
                Limit::perMinutes(10, 3)->by('quote-site-ip:'.$siteKey.'|'.$ip),
                Limit::perHour(60)->by('quote-site:'.$siteKey),
            ];
        });

        RateLimiter::for('shop-product-reviews', function (Request $request) {
            $site = $request->attributes->get('resolved_site');
            $siteKey = $site instanceof Site
                ? (string) $site->id
                : sha1($request->getHost());
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('product-review-ip:'.$ip),
                Limit::perMinute(30)->by('product-review-site:'.sha1($request->getHost())),
                Limit::perMinutes(10, 3)->by('product-review-site-ip:'.$siteKey.'|'.$ip),
                Limit::perHour(60)->by('product-review-site:'.$siteKey),
            ];
        });
        // Customer login is capped per-IP, per-host and per-target-address so one
        // caller cannot spray a victim's inbox from many IPs.
        RateLimiter::for('shop-account-login', function (Request $request) {
            return [
                Limit::perMinute(5)->by('shop-login-ip:'.$request->ip()),
                Limit::perMinute(30)->by('shop-login-site:'.sha1($request->getHost())),
                Limit::perMinute(3)->by('shop-login-email:'.sha1(
                    strtolower((string) $request->input('email')).'|'.$request->getHost()
                )),
            ];
        });

        // Authenticated account writes (address book). Keyed by the SESSION customer, never by a posted
        // field: the login limiter's email key degenerates to one site-wide bucket when no email is
        // posted, which would let one customer starve every other customer on the site.
        RateLimiter::for('shop-account-write', function (Request $request) {
            $customerId = $request->user('customer')?->getAuthIdentifier();

            return [
                Limit::perMinute(30)->by('shop-write-customer:'.($customerId !== null ? (string) $customerId : 'ip:'.$request->ip()).'|'.$request->getHost()),
                Limit::perMinute(300)->by('shop-write-site:'.sha1($request->getHost())),
            ];
        });
    }

    /** Public CSP report collector: per-IP (30/min, keyed on the IPv6 /64) plus a short-decay global ceiling. */
    protected function configureCspReportRateLimiter(): void
    {
        RateLimiter::for('csp-report', function (Request $request) {
            return [
                Limit::perMinute(30)->by('csp-ip:'.$this->rateLimitIp((string) $request->ip())),
                Limit::perMinute(180)->by('csp-global'),
            ];
        });
    }

    /**
     * Collapse an IPv6 address to its /64 prefix; IPv4 is keyed in full.
     *
     * Shared by the CSP collector's per-IP bucket and the MCP per-IP ceiling — both are
     * credential-independent limits on a pre-authentication surface, so both are only as strong as the
     * smallest unit of "one caller" they can key on.
     *
     * Three cases must NOT be collapsed:
     *
     * - IPv4-mapped (`::ffff:0:0/96`) and NAT64 (`64:ff9b::/96`) addresses carry an
     *   IPv4 in their low 32 bits and share their whole prefix. Collapsing to /64
     *   puts every IPv4 client behind a dual-stack listener or a normalising proxy
     *   into a single bucket, so one host denies the collector to all of them —
     *   strictly worse than keying the full address, which is what it replaced.
     * - Loopback and unspecified are single hosts, not subnets.
     * - Unparseable input must not be passed through as its own key, or anything
     *   able to influence the client address mints unlimited buckets and the cap
     *   stops meaning anything. It shares one bucket instead.
     */
    protected function rateLimitIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return 'unparseable';
        }

        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        // Loopback and unspecified are single hosts, not subnets. Checked before the
        // prefix families below, because `::` and `::1` both sit inside `::/96`.
        if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15)."\x01") {
            return (string) inet_ntop($packed);
        }

        // Every family that carries an IPv4 address in its low 32 bits and shares the
        // rest of the prefix. Collapsing any of these to /64 puts unrelated clients in
        // one bucket, which is what the /64 change originally set out to stop:
        //
        //   ::ffff:0:0/96    IPv4-mapped
        //   ::ffff:0:0:0/96  SIIT translated
        //   ::/96            IPv4-compatible
        //   64:ff9b::/96     NAT64 well-known
        //   64:ff9b:1::/96   NAT64 local within RFC 8215's /48
        //
        // ONLY /96-length embeddings. RFC 8215 reserves 64:ff9b:1::/48 for local
        // translation, but RFC 6052 puts the IPv4 at a different offset for each
        // prefix length — at /48 it sits at bits 48-63 and 72-87, and bytes 12-15 are
        // required to be zero. Matching the whole /48 and reading the low 32 bits
        // mapped every such client to 0.0.0.0: one shared bucket, strictly worse than
        // the /64 fallback it replaced. a review caught it.
        // Longer prefixes now fall through to /64, which at least distinguishes the
        // leading octets.
        $carriesIpv4 = str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")           // ::ffff:0:0/96
            || str_starts_with($packed, "\0\0\0\0\0\0\0\0\xff\xff\0\0")                    // ::ffff:0:0:0/96
            || str_starts_with($packed, str_repeat("\0", 12))                               // ::/96
            || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))            // 64:ff9b::/96
            || str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01".str_repeat("\0", 6));   // 64:ff9b:1::/96

        if ($carriesIpv4) {
            return (string) inet_ntop(substr($packed, 12, 4));
        }

        // Zero the interface identifier (the low 64 bits) and re-present the prefix.
        return (string) inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /**
     * Emit ` nonce="…"` when Vite has a CSP nonce for this request.
     */
    protected function configureCspNonceDirective(): void
    {
        Blade::directive('cspNonce', function (): string {
            return '<?php echo ($__cspNonce = \Illuminate\Support\Facades\Vite::cspNonce()) ? \' nonce="\'.e($__cspNonce).\'"\' : \'\'; ?>';
        });
    }

    /**
     * Livewire update requests only replay middleware on this allowlist.
     * Custom role + verification aliases are otherwise dropped after the
     * initial page load, leaving a stale-snapshot privilege window.
     */
    protected function configureLivewirePersistentMiddleware(): void
    {
        Livewire::addPersistentMiddleware([
            EnsureAgentRole::class,
            EnsureClientUser::class,
            EnsureUserIsAdmin::class,
            EnsureEmailIsVerified::class,
        ]);
    }

    /** Extend the trusted-proxy list from TRUSTED_PROXIES_EXTRA (internal reverse-proxy CIDRs). */
    protected function extendTrustedProxiesFromEnv(): void
    {
        $extras = array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_INTERNAL_PROXIES', ''))
        ));

        if ($extras === []) {
            return;
        }

        $refl = new \ReflectionClass(TrustProxies::class);
        $prop = $refl->getProperty('alwaysTrustProxies');
        $prop->setAccessible(true);
        $current = (array) ($prop->getValue() ?? []);
        $prop->setValue(null, array_values(array_unique(array_merge($current, $extras))));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register the known revision scopes at boot.
     * Lane A2 (webmcp-commerce) registers `shop` beside `page` and `site`.
     */
    private function registerRevisionScopes(): void
    {
        RevisionScopes::register(
            'page',
            'revision_base',
            static fn (
                EditorContext $context,
                int $expectedRevision,
                EditorState $state,
            ): ?\App\Services\Site\Editor\OperationResult => null,
        );
        RevisionScopes::register(
            'site',
            'composition_revision',
            static function (
                EditorContext $context,
                int $expectedRevision,
                EditorState $state,
            ): ?OperationResult {
                app(CompositionService::class)
                    ->ensureDraftRow($context->site, $context->actor->id);
                $currentRevision = (int) SiteDraft::query()
                    ->where('site_id', $context->site->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->admin_revision;

                return $currentRevision === $expectedRevision
                    ? null
                    : OperationResult::fail(
                        'stale_revision',
                        'Site draft has moved.',
                        $state,
                        ['current_composition_revision' => $currentRevision],
                    );
            },
        );
        RevisionScopes::register(
            'shop',
            'catalogue_revision',
            static function (
                EditorContext $context,
                int $expectedRevision,
                EditorState $state,
            ): ?OperationResult {
                $ids = ShopWriteOperation::declaredSubjectProductIds();
                if ($ids === null) {
                    $incoming = ShopWriteOperation::incoming();
                    if ($incoming === null) {
                        return OperationResult::fail(
                            'internal',
                            'Shop write did not declare subject products.',
                            $state,
                        );
                    }

                    try {
                        ShopWriteOperation::bindSite($context->site);
                        $ids = array_values(array_unique(array_map(
                            intval(...),
                            $incoming['op']->subjectProductIds($incoming['input']),
                        )));
                    } catch (OperationFailed $exception) {
                        ShopWriteOperation::clearIncoming();

                        return $exception->result;
                    }
                }

                $locks = ShopWriteOperation::lockSubject(
                    $context->site,
                    $ids,
                    $context->actor->id,
                );

                if (((int) $locks->draft->catalogue_revision) === $expectedRevision) {
                    return null;
                }

                $incoming = ShopWriteOperation::incoming();
                $code = $incoming['op']->revisionMismatchCode();
                ShopWriteOperation::clearIncoming();

                return OperationResult::fail(
                    $code,
                    'Shop catalogue has moved.',
                    $state,
                    ['current_catalogue_revision' => (int) $locks->draft->catalogue_revision],
                );
            },
        );
    }

    /**
     * Register the closed set of warning codes at boot and seal the registry.
     * Lane A2 (webmcp-commerce) may register additional codes here.
     */
    private function registerWarningCodes(): void
    {
        WarningCodes::registerDefaults();
        WarningCodes::register('preview_unavailable');
        WarningCodes::seal();
    }
}
