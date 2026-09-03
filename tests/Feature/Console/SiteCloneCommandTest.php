<?php

use App\Models\GeneratedPage;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Services\Site\SiteClone\SiteCloneSpacesCopier;
use Illuminate\Support\Facades\DB;

function cloneOf(Site $source): ?Site
{
    return Site::query()
        ->where('business_name', $source->business_name)
        ->where('id', '!=', $source->id)
        ->first();
}

it('clones a site in place by preview_domain token, leaving the source untouched', function () {
    $site = Site::factory()->create(['preview_domain' => 'keisha-demo']);
    $sourceRow = (array) DB::table('sites')->where('id', $site->id)->first();

    $this->artisan('site:clone', [
        'site' => 'keisha-demo',
        '--skip-spaces' => true,
    ])->assertSuccessful();

    $clone = cloneOf($site);
    expect($clone)->not->toBeNull()
        ->and($clone->preview_domain)->toBe('keisha-demo-clone')
        ->and($clone->custom_domain)->toBeNull();

    expect((array) DB::table('sites')->where('id', $site->id)->first())->toBe($sourceRow);
});

it('uses --preview-domain verbatim for the clone slug', function () {
    $site = Site::factory()->create(['preview_domain' => 'keisha-demo']);

    $this->artisan('site:clone', [
        'site' => (string) $site->id,
        '--preview-domain' => 'keisha-demo-v2',
        '--skip-spaces' => true,
    ])->assertSuccessful();

    expect(cloneOf($site)->preview_domain)->toBe('keisha-demo-v2');
});

it('refuses to run in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('site:clone', [
        'site' => '1',
        '--skip-spaces' => true,
    ])
        ->expectsOutputToContain('Refusing to run in production')
        ->assertFailed();
});

it('fails with a clear error for an unknown site token', function () {
    $this->artisan('site:clone', [
        'site' => 'no-such-site',
        '--skip-spaces' => true,
    ])
        ->expectsOutputToContain('no-such-site')
        ->assertFailed();
});

it('mirrors the site-media root alongside previews and sites roots', function () {
    $site = Site::factory()->create();
    $previewRoot = config('services.storage.preview_root');
    $portraitKey = "site-media/{$site->id}/portraits/abc.webp";

    $listed = [];
    $copied = [];
    $copier = Mockery::mock(SiteCloneSpacesCopier::class);
    $copier->shouldReceive('listKeys')
        ->andReturnUsing(function (string $prefix) use (&$listed, $portraitKey, $site): array {
            $listed[] = $prefix;

            return $prefix === "site-media/{$site->id}/" ? [$portraitKey] : [];
        });
    $copier->shouldReceive('copyObject')
        ->andReturnUsing(function (string $oldKey, string $newKey) use (&$copied): ?string {
            $copied[] = [$oldKey, $newKey];

            return null;
        });
    app()->instance(SiteCloneSpacesCopier::class, $copier);

    $this->artisan('site:clone', ['site' => (string) $site->id])->assertSuccessful();

    $clone = cloneOf($site);
    expect($listed)->toContain("{$previewRoot}/{$site->id}/")
        ->toContain("sites/{$site->id}/")
        ->toContain("site-media/{$site->id}/")
        ->and($copied)->toContain([$portraitKey, "site-media/{$clone->id}/portraits/abc.webp"]);
});

function trackingCopier(array &$state, array $keysByPrefix, ?string $failOnOldKey = null): void
{
    $state = ['copied' => [], 'deleted' => []];
    $copier = Mockery::mock(SiteCloneSpacesCopier::class);
    $copier->shouldReceive('listKeys')
        ->andReturnUsing(fn (string $prefix): array => $keysByPrefix[$prefix] ?? []);
    $copier->shouldReceive('copyObject')
        ->andReturnUsing(function (string $oldKey, string $newKey) use (&$state, $failOnOldKey): ?string {
            if ($oldKey === $failOnOldKey) {
                return 'AccessDenied Access Denied';
            }
            $state['copied'][] = $newKey;

            return null;
        });
    $copier->shouldReceive('deleteObject')
        ->andReturnUsing(function (string $key) use (&$state): ?string {
            $state['deleted'][] = $key;

            return null;
        });
    app()->instance(SiteCloneSpacesCopier::class, $copier);
}

it('deletes exactly the objects it mirrored when the database clone fails', function () {
    $taken = Site::factory()->create(['preview_domain' => 'taken-slug']);
    $site = Site::factory()->create();
    $root = config('services.storage.preview_root');
    $state = [];
    trackingCopier($state, [
        "{$root}/{$site->id}/" => ["{$root}/{$site->id}/hero.jpg", "{$root}/{$site->id}/logo.png"],
        "sites/{$site->id}/" => ["sites/{$site->id}/brand/og.jpg"],
    ]);

    $this->artisan('site:clone', [
        'site' => (string) $site->id,
        '--preview-domain' => $taken->preview_domain,
    ])
        ->expectsOutputToContain('Database clone failed')
        ->expectsOutputToContain('removed 3 mirrored object(s)')
        ->assertFailed();

    expect($state['copied'])->toHaveCount(3)
        ->and(collect($state['deleted'])->sort()->values()->all())
        ->toBe(collect($state['copied'])->sort()->values()->all());
});

it('rolls back objects already mirrored when a later copy fails', function () {
    $site = Site::factory()->create();
    $root = config('services.storage.preview_root');
    $state = [];
    trackingCopier($state, [
        "{$root}/{$site->id}/" => ["{$root}/{$site->id}/hero.jpg", "{$root}/{$site->id}/logo.png"],
    ], failOnOldKey: "{$root}/{$site->id}/logo.png");

    $this->artisan('site:clone', ['site' => (string) $site->id])
        ->expectsOutputToContain('Spaces mirror failed')
        ->expectsOutputToContain('removed 1 mirrored object(s)')
        ->assertFailed();

    expect($state['copied'])->toHaveCount(1)
        ->and($state['deleted'])->toBe($state['copied']);
});

it('rewrites sites.home_hero_video_poster_path onto the clone id', function () {
    $previewRoot = config('services.storage.preview_root');
    $site = Site::factory()->create();
    $site->update(['home_hero_video_poster_path' => "{$previewRoot}/{$site->id}/hero-poster.webp"]);

    $this->artisan('site:clone', [
        'site' => (string) $site->id,
        '--skip-spaces' => true,
    ])->assertSuccessful();

    $clone = cloneOf($site);
    expect($clone->home_hero_video_poster_path)->toBe("{$previewRoot}/{$clone->id}/hero-poster.webp");
});

it('clones previews despite the globally-unique slug', function () {
    $site = Site::factory()->create();
    Preview::factory()->for($site)->create(['slug' => 'wilkes-preview']);

    $this->artisan('site:clone', [
        'site' => (string) $site->id,
        '--skip-spaces' => true,
    ])->assertSuccessful();

    $clone = cloneOf($site);
    expect(Preview::query()->where('site_id', $clone->id)->value('slug'))
        ->toBe("wilkes-preview-c{$clone->id}")
        ->and(Preview::query()->where('site_id', $site->id)->value('slug'))->toBe('wilkes-preview');
});

it('remaps sites.overlay_logo_concept_id to the cloned logo concept', function () {
    $site = Site::factory()->create();
    $concept = LogoConcept::factory()->for($site)->create();
    $site->update(['overlay_logo_concept_id' => $concept->id]);

    $this->artisan('site:clone', [
        'site' => (string) $site->id,
        '--skip-spaces' => true,
    ])->assertSuccessful();

    $clone = cloneOf($site);
    $clonedConcept = LogoConcept::query()->where('site_id', $clone->id)->sole();

    expect($clone->overlay_logo_concept_id)->toBe($clonedConcept->id)
        ->and($clonedConcept->id)->not->toBe($concept->id);
});
