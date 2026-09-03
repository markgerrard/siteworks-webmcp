<?php

use App\Enums\AgentRole;
use App\Models\HeroVersion;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use App\Services\PreviewSnapshotWriter;
use App\Services\Site\HeroVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Open a second Postgres session and hold the canonical (site, page_type, slot)
 * advisory lock used by HeroVersionService. Caller must roll back + purge.
 */
function holdHeroActivationLock(int $siteId, string $pageType, string $slot): \Illuminate\Database\Connection
{
    $name = 'hero_lock_probe';
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
    DB::purge($name);
    $probe = DB::connection($name);
    $probe->beginTransaction();
    $hash = crc32($pageType."\0".$slot);
    if ($hash > 0x7FFFFFFF) {
        $hash -= 0x100000000;
    }
    $probe->statement('SELECT pg_advisory_xact_lock(?, ?)', [$siteId, $hash]);

    return $probe;
}

function releaseHeroActivationLock(\Illuminate\Database\Connection $probe): void
{
    $probe->rollBack();
    DB::disconnect('hero_lock_probe');
    DB::purge('hero_lock_probe');
}

test('activate serialises concurrent writers via advisory lock — no unique-violation races', function () {
    $site = Site::factory()->create();

    // First activation creates the active row.
    $first = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://first.test/hero.jpg',
        'prompt' => 'one',
        'model' => 'm',
        'placement' => [],
    ]);

    expect($first->is_active)->toBeTrue();
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'home')->where('is_active', true)->count())->toBe(1);

    // Second activation must deactivate the first and insert a new active.
    $second = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://second.test/hero.jpg',
        'prompt' => 'two',
        'model' => 'm',
        'placement' => [],
    ]);

    expect($second->is_active)->toBeTrue();
    expect($first->fresh()->is_active)->toBeFalse();
    // Partial unique index invariant: exactly one active per (site, page_type).
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'home')->where('is_active', true)->count())->toBe(1);
});

test('activate on an empty-set (no prior row) still acquires lock', function () {
    // Exercises the edge case: lockForUpdate on a zero-row
    // SELECT can't grab any row lock. Advisory lock must cover this.
    $site = Site::factory()->create();

    $hv = app(HeroVersionService::class)->activate($site->id, 'contact', [
        'url' => 'https://empty-set.test/hero.jpg',
        'prompt' => 'x',
        'model' => 'm',
        'placement' => [],
    ]);

    expect($hv->is_active)->toBeTrue();
    expect($hv->site_id)->toBe($site->id);
    expect($hv->page_type)->toBe('contact');
});

test('different (site, page_type) pairs do not serialise against each other', function () {
    // Advisory lock key is (site_id, crc32(page_type)) — different keys
    // should not contend.
    $site1 = Site::factory()->create();
    $site2 = Site::factory()->create();

    $a = app(HeroVersionService::class)->activate($site1->id, 'home', [
        'url' => 'https://a.test/h.jpg', 'prompt' => 'a', 'model' => 'm', 'placement' => [],
    ]);
    $b = app(HeroVersionService::class)->activate($site2->id, 'home', [
        'url' => 'https://b.test/h.jpg', 'prompt' => 'b', 'model' => 'm', 'placement' => [],
    ]);
    $c = app(HeroVersionService::class)->activate($site1->id, 'about', [
        'url' => 'https://c.test/h.jpg', 'prompt' => 'c', 'model' => 'm', 'placement' => [],
    ]);

    foreach ([$a, $b, $c] as $hv) {
        expect($hv->is_active)->toBeTrue();
    }
});

test('activate hero does not deactivate the intro slot for the same page_type', function () {
    // Regression: activate() must scope the deactivate UPDATE to (page_type, slot).
    // Without the slot filter, re-activating a hero row would kill the active intro
    // row as collateral, losing the intro image from the renderer's slot='intro' query.
    $site = Site::factory()->create();

    // 1. Activate a hero.
    $hero = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'hero-prompt',
        'model' => 'm',
        'placement' => [],
    ], 'hero');

    // 2. Activate an intro for the same page_type.
    $intro = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/intro.jpg',
        'prompt' => 'intro-prompt',
        'model' => 'm',
        'placement' => [],
    ], 'intro');

    expect($hero->fresh()->is_active)->toBeTrue();
    expect($intro->fresh()->is_active)->toBeTrue();

    // 3. Re-activate a different hero — must NOT touch the intro row.
    $newHero = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/hero2.jpg',
        'prompt' => 'hero2-prompt',
        'model' => 'm',
        'placement' => [],
    ], 'hero');

    expect($newHero->is_active)->toBeTrue();
    expect($hero->fresh()->is_active)->toBeFalse(); // old hero deactivated
    expect($intro->fresh()->is_active)->toBeTrue(); // intro untouched

    // Exactly one active per slot per page_type.
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'home')->where('slot', 'hero')->where('is_active', true)->count())->toBe(1);
    expect(HeroVersion::where('site_id', $site->id)->where('page_type', 'home')->where('slot', 'intro')->where('is_active', true)->count())->toBe(1);
});

test('activate throws InvalidArgumentException for unknown slot values', function () {
    $site = Site::factory()->create();

    expect(fn () => app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://example.test/hero.jpg',
        'prompt' => 'p',
        'model' => 'm',
        'placement' => [],
    ], 'banner'))->toThrow(\InvalidArgumentException::class);
});

test('activate accepts band_2 and band_3 and keeps one active row per slot', function () {
    $site = Site::factory()->create();
    foreach (['band_2', 'band_3'] as $slot) {
        $first = app(HeroVersionService::class)->activate($site->id, 'home',
            ['url' => "https://x.test/$slot-1.jpg", 'prompt' => 'p', 'model' => 'm', 'placement' => []], $slot);
        $second = app(HeroVersionService::class)->activate($site->id, 'home',
            ['url' => "https://x.test/$slot-2.jpg", 'prompt' => 'p', 'model' => 'm', 'placement' => []], $slot);

        expect($first->fresh()->is_active)->toBeFalse()
            ->and($second->fresh()->is_active)->toBeTrue()
            ->and(HeroVersion::where('site_id', $site->id)->where('slot', $slot)
                ->where('is_active', true)->count())->toBe(1);
    }
    // cross-slot independence: band_2's flip never touched band_3
    expect(HeroVersion::where('site_id', $site->id)->where('is_active', true)->count())->toBe(2);
});

test('activate serialises concurrent band_2 writers via advisory lock — no unique-violation races', function () {
    $site = Site::factory()->create();

    $first = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://first.test/band_2.jpg',
        'prompt' => 'one',
        'model' => 'm',
        'placement' => [],
    ], 'band_2');

    expect($first->is_active)->toBeTrue();
    expect(HeroVersion::where('site_id', $site->id)->where('slot', 'band_2')->where('is_active', true)->count())->toBe(1);

    $second = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://second.test/band_2.jpg',
        'prompt' => 'two',
        'model' => 'm',
        'placement' => [],
    ], 'band_2');

    expect($second->is_active)->toBeTrue();
    expect($first->fresh()->is_active)->toBeFalse();
    expect(HeroVersion::where('site_id', $site->id)->where('slot', 'band_2')->where('is_active', true)->count())->toBe(1);
});

test('partial unique index enforces the invariant even if a caller bypasses the service', function () {
    // If some future code path bypasses HeroVersionService::activate and
    // tries to insert a second is_active=true for the same (site, page_type),
    // the index must reject it.
    $site = Site::factory()->create();

    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'url' => 'https://one.test/h.jpg',
        'prompt' => '1',
        'model' => 'm',
        'placement' => [],
        'is_active' => true,
    ]);

    expect(fn () => HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'url' => 'https://two.test/h.jpg',
        'prompt' => '2',
        'model' => 'm',
        'placement' => [],
        'is_active' => true,
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('activateExisting deactivates the prior active row and activates the given version', function () {
    $site = Site::factory()->create();
    $first = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://first.test/hero.jpg',
        'prompt' => 'one',
        'model' => 'm',
        'placement' => [],
    ]);
    $inactive = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
        'url' => 'https://second.test/hero.jpg',
    ]);

    $activated = app(HeroVersionService::class)->activateExisting($inactive);

    expect($activated->is_active)->toBeTrue()
        ->and($first->fresh()->is_active)->toBeFalse()
        ->and(HeroVersion::where('site_id', $site->id)->where('page_type', 'home')
            ->where('slot', 'hero')->where('is_active', true)->count())->toBe(1);
});

test('activateExisting does not deactivate a sibling slot on the same page_type', function () {
    $site = Site::factory()->create();
    $hero = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://hero.test/h.jpg', 'prompt' => 'h', 'model' => 'm', 'placement' => [],
    ], 'hero');
    $intro = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://intro.test/i.jpg', 'prompt' => 'i', 'model' => 'm', 'placement' => [],
    ], 'intro');
    $heroAlt = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home', 'slot' => 'hero', 'is_active' => false,
        'url' => 'https://hero2.test/h.jpg',
    ]);

    app(HeroVersionService::class)->activateExisting($heroAlt);

    expect($heroAlt->fresh()->is_active)->toBeTrue()
        ->and($hero->fresh()->is_active)->toBeFalse()
        ->and($intro->fresh()->is_active)->toBeTrue();
});

test('activateExisting contends with activate on the same (site, page_type, slot) advisory lock', function () {
    $site = Site::factory()->create();
    $inactive = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
    ]);

    $probe = holdHeroActivationLock($site->id, 'home', 'hero');
    try {
        DB::statement("SET LOCAL lock_timeout = '500ms'");

        expect(fn () => app(HeroVersionService::class)->activateExisting($inactive))
            ->toThrow(QueryException::class);
    } finally {
        releaseHeroActivationLock($probe);
    }
});

test('page-manager activation contends with HeroVersionService on the same advisory lock', function (string $slot, string $method) {
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create();
    Preview::factory()->for($site)->create();
    $inactive = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => $slot,
        'is_active' => false,
        'url' => "https://alt.test/{$slot}.jpg",
    ]);

    $probe = holdHeroActivationLock($site->id, 'home', $slot);
    try {
        DB::statement("SET LOCAL lock_timeout = '500ms'");

        expect(fn () => Livewire::actingAs($staff)
            ->test('page-manager', ['siteId' => $site->id])
            ->call($method, $inactive->id)
        )->toThrow(QueryException::class);
    } finally {
        releaseHeroActivationLock($probe);
    }
})->with([
    'hero' => ['hero', 'activateHeroVersion'],
    'intro' => ['intro', 'activateIntroVersion'],
    'band' => ['band', 'activateBandVersion'],
]);

test('activateExistingAndRecord rolls back the flip when recording throws', function () {
    $site = Site::factory()->create();
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'hero_images' => [
                'home' => ['url' => 'https://old.test/hero.jpg'],
            ],
        ],
    ]);
    $first = app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://old.test/hero.jpg',
        'prompt' => 'one',
        'model' => 'm',
        'placement' => [],
    ]);
    $next = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
        'url' => 'https://new.test/hero.jpg',
    ]);

    $this->mock(PreviewSnapshotWriter::class, function ($mock) {
        $mock->shouldReceive('mutate')->once()->andThrow(new \RuntimeException('snapshot failed'));
    });

    expect(fn () => app(HeroVersionService::class)->activateExistingAndRecord($next, $site->fresh()))
        ->toThrow(\RuntimeException::class);

    expect($first->fresh()->is_active)->toBeTrue()
        ->and($next->fresh()->is_active)->toBeFalse()
        ->and($preview->fresh()->snapshot['hero_images']['home']['url'])->toBe('https://old.test/hero.jpg');
});

test('two-connection activateExistingAndRecord A then B leaves snapshot equal to B', function () {
    $site = Site::factory()->create();
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'hero_images' => [
                'home' => ['url' => 'https://c.test/hero.jpg'],
            ],
        ],
    ]);
    app(HeroVersionService::class)->activate($site->id, 'home', [
        'url' => 'https://c.test/hero.jpg',
        'prompt' => 'c',
        'model' => 'm',
        'placement' => [],
    ]);
    $versionA = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
        'url' => 'https://a.test/hero.jpg',
        'watermark_url' => 'https://a.test/wm.jpg',
        'prompt' => 'a',
        'model' => 'm-a',
        'placement' => ['text_zone' => 'bottom-left'],
    ]);
    $versionB = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
        'url' => 'https://b.test/hero.jpg',
        'watermark_url' => 'https://b.test/wm.jpg',
        'prompt' => 'b',
        'model' => 'm-b',
        'placement' => ['text_zone' => 'top-right'],
    ]);

    $lockHeldDuringRecord = false;
    $siteId = $site->id;
    $this->app->bind(PreviewSnapshotWriter::class, function () use (&$lockHeldDuringRecord, $siteId) {
        return new class($lockHeldDuringRecord, $siteId) extends PreviewSnapshotWriter
        {
            public function __construct(private bool &$lockHeldDuringRecord, private int $siteId) {}

            public function mutate($preview, callable $modifier): void
            {
                $name = 'hero_record_lock_probe';
                config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
                DB::purge($name);
                $probe = DB::connection($name);
                $hash = crc32("home\0hero");
                if ($hash > 0x7FFFFFFF) {
                    $hash -= 0x100000000;
                }
                $row = $probe->selectOne('SELECT pg_try_advisory_lock(?, ?) AS got', [$this->siteId, $hash]);
                $this->lockHeldDuringRecord = ! (bool) ($row->got ?? true);
                if ($row->got ?? false) {
                    $probe->statement('SELECT pg_advisory_unlock(?, ?)', [$this->siteId, $hash]);
                }
                DB::disconnect($name);
                DB::purge($name);

                parent::mutate($preview, $modifier);
            }
        };
    });

    $service = app(HeroVersionService::class);
    $service->activateExistingAndRecord($versionA, $site->fresh());
    expect($lockHeldDuringRecord)->toBeTrue();

    $service->activateExistingAndRecord($versionB, $site->fresh());

    expect($versionB->fresh()->is_active)->toBeTrue()
        ->and($versionA->fresh()->is_active)->toBeFalse()
        ->and($preview->fresh()->snapshot['hero_images']['home']['url'])->toBe('https://b.test/hero.jpg')
        ->and($preview->fresh()->snapshot['hero_images']['home']['prompt'])->toBe('b');
});

test('page-manager uploadHero contends with HeroVersionService on the same advisory lock', function () {
    Storage::fake('s3');
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create();
    Preview::factory()->for($site)->create();

    $bytes = file_get_contents(base_path('tests/fixtures/logos/large_1024x512.png'));
    $upload = UploadedFile::fake()->createWithContent('agent.png', $bytes);

    $probe = holdHeroActivationLock($site->id, 'home', 'hero');
    try {
        DB::statement("SET LOCAL lock_timeout = '500ms'");

        expect(fn () => Livewire::actingAs($staff)
            ->test('page-manager', ['siteId' => $site->id])
            ->set('heroUpload', $upload)
            ->call('uploadHero', 'home', 'hero')
        )->toThrow(QueryException::class);
    } finally {
        releaseHeroActivationLock($probe);
    }
});
