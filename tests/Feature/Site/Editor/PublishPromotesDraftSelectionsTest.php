<?php

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Services\PreviewSnapshotWriter;
use App\Services\Site\CompositionDefaults;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\HeroVersionService;
use App\Services\Site\LogoSelectionService;
use App\Services\Site\PublicPageCache;
use App\Services\Site\SitePublishService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{Site, GeneratedPage, Preview}
 */
$createPublishableSite = function (): array {
    $site = Site::factory()->create(['theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Publish selection'],
            ['type' => 'cta', 'title' => 'Call now'],
        ]],
    ]);
    $page->update([
        'published_revision_id' => $revision->id,
        'draft_revision_id' => $revision->id,
    ]);
    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'admin_revision' => 0,
        'updated_at' => now(),
    ]);
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => ['hero_images' => ['home' => ['url' => 'https://old.test/hero.jpg']]],
    ]);

    return [$site->fresh(), $page->fresh(), $preview];
};

beforeEach(function () {
    Queue::fake();
    Storage::fake('s3');
});

it('promotes a drafted hero and mirrors it after commit', function () use ($createPublishableSite) {
    [$site, , $preview] = $createPublishableSite();
    $mirrorTransactionLevel = null;
    $this->app->bind(PreviewSnapshotWriter::class, function () use (&$mirrorTransactionLevel) {
        return new class($mirrorTransactionLevel) extends PreviewSnapshotWriter
        {
            public function __construct(private ?int &$mirrorTransactionLevel) {}

            public function mutate(Preview $preview, callable $modifier): void
            {
                $this->mirrorTransactionLevel = DB::transactionLevel();
                parent::mutate($preview, $modifier);
            }
        };
    });
    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://old.test/hero.jpg',
    ]);
    $draft = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://new.test/hero.jpg',
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);
    $outerTransactionLevel = DB::transactionLevel();

    $version = app(SitePublishService::class)->publishSite($site);

    expect($version->exists)->toBeTrue()
        ->and($active->fresh()->is_active)->toBeFalse()
        ->and($draft->fresh()->is_active)->toBeTrue()
        ->and($mirrorTransactionLevel)->toBe($outerTransactionLevel)
        ->and($preview->fresh()->snapshot['hero_images']['home']['url'])->toBe($draft->url)
        ->and(app(DraftAssetSelections::class)->any($site))->toBeFalse();
});

it('leaves a new selection key added between reads pending for the next publish', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $home = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    $about = HeroVersion::factory()->for($site)->create(['page_type' => 'about', 'slot' => 'hero']);
    $selections = app(DraftAssetSelections::class);
    $selections->setHero($site, 'home', 'hero', $home, null);

    $service = new class(
        app(CompositionService::class),
        app(CompositionDefaults::class),
        app(PublicPageCache::class),
        fn (Site $lockedSite) => $selections->setHero($lockedSite, 'about', 'hero', $about, null),
    ) extends SitePublishService
    {
        public function __construct(
            CompositionService $composition,
            CompositionDefaults $defaults,
            PublicPageCache $pageCache,
            private readonly Closure $afterLocks,
        ) {
            parent::__construct($composition, $defaults, $pageCache);
        }

        protected function afterAdvisoryLocks(Site $site): void
        {
            ($this->afterLocks)($site);
        }
    };

    $service->publishSite($site);

    expect($home->fresh()->is_active)->toBeTrue()
        ->and($about->fresh()->is_active)->toBeFalse()
        ->and($selections->heroFor($site, 'about', 'hero')?->is($about))->toBeTrue();

    app(SitePublishService::class)->publishSite($site->fresh());

    expect($about->fresh()->is_active)->toBeTrue()
        ->and($selections->any($site))->toBeFalse();
});

it('promotes a drafted logo without bumping the admin revision', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $selected = LogoConcept::factory()->for($site)->selected()->create(['path' => 'logos/old.png']);
    $draft = LogoConcept::factory()->for($site)->create(['path' => 'logos/new.png']);
    app(DraftAssetSelections::class)->setLogo($site, $draft, null);

    app(SitePublishService::class)->publishSite($site);

    expect($selected->fresh()->is_selected)->toBeFalse()
        ->and($draft->fresh()->is_selected)->toBeTrue()
        ->and((int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision'))->toBe(0)
        ->and(app(DraftAssetSelections::class)->any($site))->toBeFalse();
});

it('reports a snapshot mirror failure and still returns the published version', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $draft = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);
    $failure = new RuntimeException('snapshot mirror failed');
    $this->mock(PreviewSnapshotWriter::class, function ($mock) use ($failure) {
        $mock->shouldReceive('mutate')->once()->andThrow($failure);
    });
    $this->mock(ExceptionHandler::class, function ($mock) use ($failure) {
        $mock->shouldReceive('report')->once()->with($failure);
    });

    $version = app(SitePublishService::class)->publishSite($site);

    expect($version->exists)->toBeTrue()
        ->and($draft->fresh()->is_active)->toBeTrue();
});

it('discard clears draft selections without changing live assets', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $active = HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'hero']);
    $draft = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);

    app(SitePublishService::class)->discardAllDrafts($site);

    expect(app(DraftAssetSelections::class)->any($site))->toBeFalse()
        ->and($active->fresh()->is_active)->toBeTrue()
        ->and($draft->fresh()->is_active)->toBeFalse();
});

it('a picker activation wins over an older draft selection', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $draft = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    $picked = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);

    app(HeroVersionService::class)->activateExistingAndRecord($picked, $site);

    expect(app(DraftAssetSelections::class)->any($site))->toBeFalse()
        ->and($picked->fresh()->is_active)->toBeTrue();
});

it('a logo picker selection clears the matching draft and bumps admin revision', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $draft = LogoConcept::factory()->for($site)->create();
    $picked = LogoConcept::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setLogo($site, $draft, null);

    app(LogoSelectionService::class)->select($site, $picked, null, bumpAdmin: true);

    expect(app(DraftAssetSelections::class)->any($site))->toBeFalse()
        ->and($picked->fresh()->is_selected)->toBeTrue()
        ->and((int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision'))->toBe(1);
});

it('exposes pending selections in the publish summary and editor state', function () use ($createPublishableSite) {
    [$site, $page] = $createPublishableSite();
    $page->update(['draft_revision_id' => null]);
    $draft = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://pending.test/hero.jpg',
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);

    $summary = app(SitePublishService::class)->publishSummary($site);
    $state = app(EditorStateFactory::class)->for($site, $page->fresh());

    expect($summary['pending_asset_selections'])->toBe([
        [
            'family' => 'hero',
            'page_type' => 'home',
            'slot' => 'hero',
            'version_id' => $draft->id,
            'url' => $draft->url,
            'mode' => null,
        ],
    ])->and($state->pendingPublish)->toBeTrue();
});

it('takes sorted hero advisory locks before the logo and row locks', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $selections = app(DraftAssetSelections::class);
    $selections->setHero($site, 'zeta', 'intro', HeroVersion::factory()->for($site)->create([
        'page_type' => 'zeta', 'slot' => 'intro',
    ]), null);
    $selections->setHero($site, 'alpha', 'band_2', HeroVersion::factory()->for($site)->create([
        'page_type' => 'alpha', 'slot' => 'band_2',
    ]), null);
    $selections->setLogo($site, LogoConcept::factory()->for($site)->create(), null);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock') || str_contains($query->sql, 'for update')) {
            $statements[] = [$query->sql, $query->bindings];
        }
    });

    app(SitePublishService::class)->publishSite($site);

    [$alphaSiteKey, $alphaHash] = HeroVersionService::activationLockKey($site->id, 'alpha', 'band_2');
    [$zetaSiteKey, $zetaHash] = HeroVersionService::activationLockKey($site->id, 'zeta', 'intro');
    expect($statements[0][1])->toBe([$alphaSiteKey, $alphaHash])
        ->and($statements[1][1])->toBe([$zetaSiteKey, $zetaHash])
        ->and($statements[2])->toBe(['SELECT pg_advisory_xact_lock(?)', [$site->id]])
        ->and(strtolower($statements[3][0]))->toContain('sites')
        ->and(strtolower($statements[3][0]))->not->toContain('site_drafts')
        ->and(strtolower($statements[4][0]))->toContain('site_drafts')
        ->and(strtolower($statements[5][0]))->toContain('generated_pages');
});

it('completes overlapping publish and picker activation without a deadlock', function () use ($createPublishableSite) {
    if (! function_exists('pcntl_fork')) {
        $this->fail('The bounded two-connection overlap test requires pcntl.');
    }

    [$site] = $createPublishableSite();
    $draft = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    $picked = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);

    DB::commit();
    DB::disconnect();
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->not->toBeFalse();
    [$parentSocket, $childSocket] = $sockets;
    $childPid = pcntl_fork();
    if ($childPid === 0) {
        fclose($parentSocket);
        try {
            stream_set_timeout($childSocket, 2);
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('The publish seam did not release the picker connection.');
            }
            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::setDefaultConnection('pgsql_b');
            DB::purge('pgsql_b');
            fwrite($childSocket, "started\n");
            app(HeroVersionService::class)->activateExistingAndRecord(
                $picked->setConnection('pgsql_b'),
                $site->setConnection('pgsql_b'),
            );
            fwrite($childSocket, "ok\n");
        } catch (Throwable $exception) {
            @fwrite($childSocket, 'error:'.$exception->getMessage()."\n");
        }
        fclose($childSocket);
        exit(0);
    }
    fclose($childSocket);

    $service = new class(
        app(CompositionService::class),
        app(CompositionDefaults::class),
        app(PublicPageCache::class),
        function () use ($parentSocket): void {
            fwrite($parentSocket, "go\n");
            stream_set_timeout($parentSocket, 1);
            expect(trim((string) fgets($parentSocket)))->toBe('started');
        },
    ) extends SitePublishService
    {
        public function __construct(
            CompositionService $composition,
            CompositionDefaults $defaults,
            PublicPageCache $pageCache,
            private readonly Closure $afterLocks,
        ) {
            parent::__construct($composition, $defaults, $pageCache);
        }

        protected function afterAdvisoryLocks(Site $site): void
        {
            ($this->afterLocks)();
        }
    };

    $startedAt = microtime(true);
    $service->publishSite($site->fresh());
    $deadline = microtime(true) + 5;
    do {
        $result = pcntl_waitpid($childPid, $status, WNOHANG);
        if ($result === $childPid) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    if ($result !== $childPid) {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
        $this->fail('The overlapping picker activation did not finish within 5 seconds.');
    }

    stream_set_blocking($parentSocket, false);
    $childOutput = stream_get_contents($parentSocket);
    fclose($parentSocket);

    expect(microtime(true) - $startedAt)->toBeLessThan(5.0)
        ->and($childOutput)->toContain('ok')
        ->not->toContain('SQLSTATE[40P01');
});
