<?php

use App\Enums\AgentRole;
use App\Enums\HeroVersionSource;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\AutoPublishCoordinator;
use App\Services\Site\CompositionDefaults;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\HeroVersionService;
use App\Services\Site\PublicPageCache;
use App\Services\Site\SitePublishService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @return array{Site, GeneratedPage, Preview}
 */
$createPublishableSite = function (?User $owner = null): array {
    $site = Site::factory()->create([
        'theme' => 'trades-bold',
        'created_by_user_id' => $owner?->id ?? User::factory(),
        'home_hero_video_enabled' => false,
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Publish hero video'],
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

it('promotes a drafted hero_video select so publish resolves the pending diff', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $live = HeroVideoVersion::factory()->for($site)->active()->create([
        's3_key' => 'videos/live-before-select.mp4',
        'provider' => 'old-provider',
        'resolution' => '480p',
        'prompt' => 'old-prompt',
    ]);
    $selected = HeroVideoVersion::factory()->for($site)->create([
        's3_key' => 'videos/agent-select.mp4',
        'provider' => 'demo',
        'resolution' => '720p',
        'prompt' => 'selected-prompt',
    ]);
    $site->forceFill([
        'home_hero_video_path' => $live->s3_key,
        'home_hero_video_provider' => $live->provider,
        'home_hero_video_tier' => $live->resolution,
        'home_hero_video_prompt' => $live->prompt,
        'home_hero_video_status' => 'ready',
        'home_hero_video_enabled' => false,
    ])->save();
    app(DraftAssetSelections::class)->setHeroVideo($site, $selected, 'on', null);

    $summary = app(SitePublishService::class)->publishSummary($site);

    expect($summary['pending_asset_selections'])->toBe([
        [
            'family' => 'hero_video',
            'page_type' => 'home',
            'slot' => 'hero',
            'version_id' => $selected->id,
            'url' => $selected->url(),
            'mode' => 'on',
        ],
    ]);

    $adminRevisionBefore = (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');

    app(SitePublishService::class)->publishSite($site);

    $site = $site->fresh();
    expect($selected->fresh()->is_active)->toBeTrue()
        ->and($live->fresh()->is_active)->toBeFalse()
        ->and($site->home_hero_video_enabled)->toBeTrue()
        ->and($site->home_hero_video_path)->toBe($selected->s3_key)
        ->and($site->home_hero_video_provider)->toBe($selected->provider)
        ->and($site->home_hero_video_tier)->toBe($selected->resolution)
        ->and($site->home_hero_video_prompt)->toBe($selected->prompt)
        ->and($site->home_hero_video_status)->toBe('ready')
        ->and(app(DraftAssetSelections::class)->any($site))->toBeFalse()
        ->and(app(SitePublishService::class)->publishSummary($site)['pending_asset_selections'])->toBe([])
        ->and((int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision'))->toBe($adminRevisionBefore);
});

it('promotes a drafted hero_video pause by clearing the enabled flag only', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $live = HeroVideoVersion::factory()->for($site)->active()->create([
        's3_key' => 'videos/keep-live.mp4',
        'provider' => 'keep-provider',
        'resolution' => '1080p',
        'prompt' => 'keep-prompt',
    ]);
    $site->forceFill([
        'home_hero_video_path' => $live->s3_key,
        'home_hero_video_provider' => $live->provider,
        'home_hero_video_tier' => $live->resolution,
        'home_hero_video_prompt' => $live->prompt,
        'home_hero_video_status' => 'ready',
        'home_hero_video_enabled' => true,
    ])->save();
    app(DraftAssetSelections::class)->setHeroVideo($site, null, 'off', null);

    $summary = app(SitePublishService::class)->publishSummary($site);

    expect($summary['pending_asset_selections'])->toBe([
        [
            'family' => 'hero_video',
            'page_type' => 'home',
            'slot' => 'hero',
            'version_id' => null,
            'url' => null,
            'mode' => 'off',
        ],
    ]);

    $adminRevisionBefore = (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');

    app(SitePublishService::class)->publishSite($site);

    $site = $site->fresh();
    expect($live->fresh()->is_active)->toBeTrue()
        ->and($site->home_hero_video_enabled)->toBeFalse()
        ->and($site->home_hero_video_path)->toBe($live->s3_key)
        ->and($site->home_hero_video_provider)->toBe($live->provider)
        ->and($site->home_hero_video_tier)->toBe($live->resolution)
        ->and($site->home_hero_video_prompt)->toBe($live->prompt)
        ->and($site->home_hero_video_status)->toBe('ready')
        ->and(app(DraftAssetSelections::class)->any($site))->toBeFalse()
        ->and((int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision'))->toBe($adminRevisionBefore);
});

it('shows the pending hero video mode on the unpublished-changes banner', function () use ($createPublishableSite) {
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    [$site] = $createPublishableSite($staff);
    $video = HeroVideoVersion::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', $staff->id);

    Livewire::actingAs($staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true)
        ->assertSee('this publish switches the home hero to video');

    app(DraftAssetSelections::class)->setHeroVideo($site, null, 'off', $staff->id);

    Livewire::actingAs($staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true)
        ->assertSee('this publish switches the home hero to image');
});

it('leaves admin_revision unchanged across a hero_video promotion', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $video = HeroVideoVersion::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', null);
    SiteDraft::query()->where('site_id', $site->id)->update(['admin_revision' => 4]);

    app(SitePublishService::class)->publishSite($site);

    expect((int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision'))->toBe(4)
        ->and($video->fresh()->is_active)->toBeTrue();
});

it('clears a drafted hero_video when a human activates a home hero image', function (string $path) use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $video = HeroVideoVersion::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', null);
    $picked = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://human.test/upload.jpg',
    ]);

    $activated = match ($path) {
        'activate' => app(HeroVersionService::class)->activate(
            $site->id,
            'home',
            [
                'url' => 'https://human.test/upload-activate.jpg',
                'watermark_url' => null,
                'prompt' => null,
                'model' => null,
                'placement' => null,
                'upgrade_candidate' => false,
                'source' => HeroVersionSource::UserUpload,
            ],
            'hero',
        ),
        'activateExisting' => app(HeroVersionService::class)->activateExisting($picked),
        'activateExistingAndRecord' => app(HeroVersionService::class)->activateExistingAndRecord($picked, $site),
    };

    expect(app(DraftAssetSelections::class)->heroVideoFor($site))->toBeNull()
        ->and($activated->fresh()->is_active)->toBeTrue();

    app(SitePublishService::class)->publishSite($site);

    expect($site->fresh()->home_hero_video_enabled)->toBeFalse()
        ->and($activated->fresh()->is_active)->toBeTrue()
        ->and($video->fresh()->is_active)->toBeFalse()
        ->and(app(DraftAssetSelections::class)->any($site))->toBeFalse();
})->with(['activate', 'activateExisting', 'activateExistingAndRecord']);

it('clears a drafted hero_video row when the studio toggles enabled', function () use ($createPublishableSite) {
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    [$site] = $createPublishableSite($staff);
    $video = HeroVideoVersion::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', $staff->id);

    Livewire::actingAs($staff)
        ->test('home-hero-video-studio', ['siteId' => $site->id])
        ->call('toggleEnabled');

    expect(app(DraftAssetSelections::class)->heroVideoFor($site))->toBeNull()
        ->and($site->fresh()->home_hero_video_enabled)->toBeTrue();
});

it('takes asset advisory locks then the sites row then site_drafts then generated_pages', function () use ($createPublishableSite) {
    [$site] = $createPublishableSite();
    $video = HeroVideoVersion::factory()->for($site)->create();
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', null);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock') || str_contains(strtolower($query->sql), 'for update')) {
            $statements[] = [$query->sql, $query->bindings];
        }
    });

    app(SitePublishService::class)->publishSite($site);

    [$homeSiteKey, $homeHash] = HeroVersionService::activationLockKey($site->id, 'home', 'hero');
    expect($statements[0][1])->toBe([$homeSiteKey, $homeHash])
        ->and(strtolower($statements[1][0]))->toContain('sites')
        ->and(strtolower($statements[1][0]))->not->toContain('site_drafts')
        ->and(strtolower($statements[2][0]))->toContain('site_drafts')
        ->and(strtolower($statements[3][0]))->toContain('generated_pages');
});

it('completes overlapping auto-publish and a direct publishSite without a deadlock or leftover draft', function () use ($createPublishableSite) {
    if (! function_exists('pcntl_fork')) {
        $this->fail('The bounded two-connection overlap test requires pcntl.');
    }

    [$site] = $createPublishableSite();
    $selected = HeroVideoVersion::factory()->for($site)->create([
        's3_key' => 'videos/overlap-select.mp4',
    ]);
    app(DraftAssetSelections::class)->setHeroVideo($site, $selected, 'on', null);
    $preBatchRev = (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');

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
                throw new RuntimeException('The auto-publish seam did not release the publisher connection.');
            }
            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::setDefaultConnection('pgsql_b');
            DB::purge('pgsql_b');
            fwrite($childSocket, "started\n");
            app(SitePublishService::class)->publishSite(
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
    (new AutoPublishCoordinator($service))->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: $preBatchRev,
        userId: null,
        batchId: 't8-overlap-publish',
        pagesExpected: 1,
    );
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
        $this->fail('The overlapping publishSite did not finish within 5 seconds.');
    }

    stream_set_blocking($parentSocket, false);
    $childOutput = stream_get_contents($parentSocket);
    fclose($parentSocket);

    expect(microtime(true) - $startedAt)->toBeLessThan(5.0)
        ->and($childOutput)->toContain('ok')
        ->not->toContain('SQLSTATE[40P01')
        ->and(app(DraftAssetSelections::class)->any($site->fresh()))->toBeFalse()
        ->and($selected->fresh()->is_active)->toBeTrue()
        ->and($site->fresh()->home_hero_video_enabled)->toBeTrue();
});

it('completes overlapping auto-publish and discardAllDrafts without a deadlock or leftover draft', function () use ($createPublishableSite) {
    if (! function_exists('pcntl_fork')) {
        $this->fail('The bounded two-connection overlap test requires pcntl.');
    }

    [$site] = $createPublishableSite();
    $selected = HeroVideoVersion::factory()->for($site)->create([
        's3_key' => 'videos/overlap-discard.mp4',
    ]);
    app(DraftAssetSelections::class)->setHeroVideo($site, $selected, 'on', null);
    $preBatchRev = (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');

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
                throw new RuntimeException('The auto-publish seam did not release the discard connection.');
            }
            config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
            DB::setDefaultConnection('pgsql_b');
            DB::purge('pgsql_b');
            fwrite($childSocket, "started\n");
            app(SitePublishService::class)->discardAllDrafts(
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
    (new AutoPublishCoordinator($service))->finalizeAfterBatch(
        siteId: $site->id,
        preBatchRev: $preBatchRev,
        userId: null,
        batchId: 't8-overlap-discard',
        pagesExpected: 1,
    );
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
        $this->fail('The overlapping discardAllDrafts did not finish within 5 seconds.');
    }

    stream_set_blocking($parentSocket, false);
    $childOutput = stream_get_contents($parentSocket);
    fclose($parentSocket);

    expect(microtime(true) - $startedAt)->toBeLessThan(5.0)
        ->and($childOutput)->toContain('ok')
        ->not->toContain('SQLSTATE[40P01')
        ->and(app(DraftAssetSelections::class)->any($site->fresh()))->toBeFalse();
});
