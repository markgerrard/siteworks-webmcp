<?php

use App\Enums\PageType;
use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\LayoutPreset;
use App\Models\LogoConcept;
use App\Models\Shop\ShopSnapshot;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function buildDemoSite(): array
{
    Storage::fake('s3');

    $site = Site::factory()->create(['business_name' => 'Camino Demo']);

    BusinessProfile::factory()->for($site)->create();

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => PageType::Home]);
    $homeRevision = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Camino', 'accent_word' => 'Camino'],
        ]],
    ]);
    $home->update(['published_revision_id' => $homeRevision->id]);

    $about = GeneratedPage::factory()->for($site)->create(['page_type' => PageType::About]);
    $aboutRevision = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'details', 'title' => 'About us', 'items' => []],
        ]],
    ]);
    $about->update(['published_revision_id' => $aboutRevision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRevision->id],
            ['page_id' => $about->id, 'revision_id' => $aboutRevision->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $preset = LayoutPreset::factory()->for($site)->active()->create();

    Storage::disk('s3')->put('logos/camino.png', 'fake-logo-bytes');
    $logo = LogoConcept::factory()->for($site)->selected()->create(['path' => 'logos/camino.png']);

    $snapshot = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'json' => ['products' => []],
        'status' => ShopSnapshotStatus::Success,
        'product_count' => 0,
        'built_at' => now(),
    ]);
    DB::table('shop_snapshot_current')->insert([
        'site_id' => $site->id,
        'snapshot_id' => $snapshot->id,
        'updated_at' => now(),
    ]);

    return compact('site', 'home', 'preset', 'logo', 'snapshot');
}

it('exports a bundle.json with the expected shape and copies referenced files', function () {
    ['site' => $site, 'home' => $home] = buildDemoSite();
    $out = storage_path('app/testing/bundle-'.$site->id);
    File::deleteDirectory($out);

    $this->artisan('site:export-bundle', ['site' => (string) $site->id, '--out' => $out])
        ->assertSuccessful();

    expect(File::exists("{$out}/bundle.json"))->toBeTrue();

    $bundle = json_decode(File::get("{$out}/bundle.json"), true);

    expect($bundle['manifest']['source_site_id'])->toBe($site->id)
        ->and($bundle['manifest']['tables']['sites'])->toBe(1)
        ->and($bundle['manifest']['tables']['business_profiles'])->toBe(1)
        ->and($bundle['manifest']['tables']['generated_pages'])->toBe(2)
        ->and($bundle['manifest']['tables']['generated_page_revisions'])->toBe(2)
        ->and($bundle['manifest']['tables']['site_versions'])->toBe(1)
        ->and($bundle['manifest']['tables']['site_versions_current'])->toBe(1)
        ->and($bundle['manifest']['tables']['layout_presets'])->toBe(1)
        ->and($bundle['manifest']['tables']['logo_concepts'])->toBe(1)
        ->and($bundle['manifest']['tables']['shop_snapshots'])->toBe(1)
        ->and($bundle['manifest']['tables']['shop_snapshot_current'])->toBe(1)
        ->and($bundle['manifest']['files_copied'])->toBe(1);

    // Original primary keys preserved.
    expect($bundle['tables']['sites'][0]['id'])->toBe($site->id)
        ->and($bundle['tables']['generated_pages'][0]['id'])->toBe($home->id);

    // JSON columns decoded, not double-encoded strings.
    expect($bundle['tables']['business_profiles'][0]['profile_data'])->toBeArray();
    expect($bundle['tables']['generated_page_revisions'][0]['content_data'])->toBeArray();

    // Timestamps are strings.
    expect($bundle['tables']['sites'][0]['created_at'])->toBeString();

    // The logo file was copied verbatim.
    expect(File::get("{$out}/files/logos/camino.png"))->toBe('fake-logo-bytes');
});

it('imports a bundle into an empty-of-this-site database and renders identical HTML', function () {
    ['site' => $site, 'home' => $home] = buildDemoSite();
    $out = storage_path('app/testing/bundle-roundtrip-'.$site->id);
    File::deleteDirectory($out);

    $this->artisan('site:export-bundle', ['site' => (string) $site->id, '--out' => $out])
        ->assertSuccessful();

    $originalHtml = app(PageRenderer::class)->render($site->fresh(), $home->id, mode: 'public');

    // Wipe every row this bundle owns, in FK-safe order.
    DB::table('shop_snapshot_current')->where('site_id', $site->id)->delete();
    DB::table('shop_snapshots')->where('site_id', $site->id)->delete();
    DB::table('site_versions_current')->where('site_id', $site->id)->delete();
    DB::table('site_versions')->where('site_id', $site->id)->delete();
    DB::table('logo_concepts')->where('site_id', $site->id)->delete();
    DB::table('layout_presets')->where('site_id', $site->id)->delete();
    DB::table('generated_page_revisions')->whereIn('page_id', DB::table('generated_pages')->where('site_id', $site->id)->pluck('id'))->delete();
    DB::table('generated_pages')->where('site_id', $site->id)->delete();
    DB::table('business_profiles')->where('site_id', $site->id)->delete();
    DB::table('sites')->where('id', $site->id)->delete();

    Storage::fake('local');

    $this->artisan('site:import-bundle', ['path' => $out, '--disk' => 'local'])
        ->assertSuccessful();

    expect(DB::table('sites')->where('id', $site->id)->exists())->toBeTrue();

    $restoredSite = Site::find($site->id);
    $restoredHtml = app(PageRenderer::class)->render($restoredSite, $home->id, mode: 'public');

    expect($restoredHtml)->toBe($originalHtml);
    expect(Storage::disk('local')->get('logos/camino.png'))->toBe('fake-logo-bytes');
});

it('refuses to import a bundle whose site id already exists', function () {
    ['site' => $site] = buildDemoSite();
    $out = storage_path('app/testing/bundle-dup-'.$site->id);
    File::deleteDirectory($out);

    $this->artisan('site:export-bundle', ['site' => (string) $site->id, '--out' => $out])
        ->assertSuccessful();

    $this->artisan('site:import-bundle', ['path' => $out])
        ->expectsOutputToContain('already exists')
        ->assertFailed();
});

it('warns about and skips tables missing from the target schema', function () {
    ['site' => $site] = buildDemoSite();
    $out = storage_path('app/testing/bundle-missing-'.$site->id);
    File::deleteDirectory($out);

    $this->artisan('site:export-bundle', ['site' => (string) $site->id, '--out' => $out])
        ->assertSuccessful();

    DB::table('shop_snapshot_current')->where('site_id', $site->id)->delete();
    DB::table('shop_snapshots')->where('site_id', $site->id)->delete();
    DB::table('site_versions_current')->where('site_id', $site->id)->delete();
    DB::table('site_versions')->where('site_id', $site->id)->delete();
    DB::table('logo_concepts')->where('site_id', $site->id)->delete();
    DB::table('layout_presets')->where('site_id', $site->id)->delete();
    DB::table('generated_page_revisions')->whereIn('page_id', DB::table('generated_pages')->where('site_id', $site->id)->pluck('id'))->delete();
    DB::table('generated_pages')->where('site_id', $site->id)->delete();
    DB::table('business_profiles')->where('site_id', $site->id)->delete();
    DB::table('sites')->where('id', $site->id)->delete();

    // Simulate the demo schema being a trimmed-down subset: this table is
    // in SiteBundleCatalog::TABLES but doesn't exist in the target DB.
    Schema::drop('shop_snapshot_current');

    $this->artisan('site:import-bundle', ['path' => $out])
        ->expectsOutputToContain('shop_snapshot_current')
        ->assertSuccessful();

    expect(DB::table('sites')->where('id', $site->id)->exists())->toBeTrue();
    expect(DB::table('shop_snapshots')->where('site_id', $site->id)->exists())->toBeTrue();
});

it('drops bundle columns the target schema does not have and reports them', function () {
    ['site' => $site] = buildDemoSite();
    $out = storage_path('app/testing/bundle-extracol-'.$site->id);
    File::deleteDirectory($out);
    $this->artisan('site:export-bundle', ['site' => (string) $site->id, '--out' => $out])->assertSuccessful();

    // Simulate an export from a database that had a column this schema never got.
    $bundle = json_decode(File::get($out.'/bundle.json'), true);
    $bundle['tables']['sites'][0]['news_schedule'] = 'weekly';
    File::put($out.'/bundle.json', json_encode($bundle));

    DB::table('shop_snapshot_current')->where('site_id', $site->id)->delete();
    DB::table('shop_snapshots')->where('site_id', $site->id)->delete();
    DB::table('site_versions_current')->where('site_id', $site->id)->delete();
    DB::table('site_versions')->where('site_id', $site->id)->delete();
    DB::table('logo_concepts')->where('site_id', $site->id)->delete();
    DB::table('layout_presets')->where('site_id', $site->id)->delete();
    DB::table('generated_page_revisions')->whereIn('page_id', DB::table('generated_pages')->where('site_id', $site->id)->pluck('id'))->delete();
    DB::table('generated_pages')->where('site_id', $site->id)->delete();
    DB::table('business_profiles')->where('site_id', $site->id)->delete();
    DB::table('sites')->where('id', $site->id)->delete();

    $this->artisan('site:import-bundle', ['path' => $out])
        ->expectsOutputToContain('Dropped columns not present in sites: news_schedule')
        ->assertSuccessful();

    expect(DB::table('sites')->where('id', $site->id)->exists())->toBeTrue();
});

it('refuses to import when the sites table is missing', function () {
    ['site' => $site] = buildDemoSite();
    $out = storage_path('app/testing/bundle-nosites-'.$site->id);
    File::deleteDirectory($out);
    $this->artisan('site:export-bundle', ['site' => (string) $site->id, '--out' => $out])->assertSuccessful();

    Schema::disableForeignKeyConstraints();
    Schema::drop('sites');

    expect(fn () => app(\App\Services\Site\SiteBundle\SiteBundleImportService::class)->import($out))
        ->toThrow(\RuntimeException::class, 'no sites table');
});

