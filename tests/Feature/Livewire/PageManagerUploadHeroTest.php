<?php

use App\Enums\AgentRole;
use App\Enums\HeroVersionSource;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::command('FLUSHDB');
    Storage::fake('s3');
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

function setupUploadSite(): Site
{
    $site = Site::factory()->create(['business_type' => 'Electrician']);
    BusinessProfile::factory()->for($site)->create(['profile_data' => ['summary' => 'test']]);
    Preview::factory()->for($site)->create();
    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
    ]);

    return $site;
}

/**
 * Reuse the committed binary fixtures from tests/fixtures/logos/ rather
 * than building a PNG at runtime — the dev container doesnt have GDs
 * imagecreatetruecolor compiled in, so runtime image construction would
 * fail with "Call to undefined function". The two fixtures we need
 * already exist:
 *   - large_1024x512.png → "valid" path (max dim ≥ 600)
 *   - small_300x300.png  → "too small" path (max dim < 600)
 */
function makeFixtureUpload(string $fixture, string $name = 'agent.png'): UploadedFile
{
    $bytes = file_get_contents(base_path("tests/fixtures/logos/{$fixture}"));

    // UploadedFile::fake()->createWithContent returns a Livewire-aware
    // fake with the $name property the testing harness expects, while
    // still letting us seed real PNG bytes from the committed fixture.
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('creates a UserUpload HeroVersion + sets active when an agent uploads a valid hero', function () {
    $site = setupUploadSite();

    // Pre-existing active AI hero — must be deactivated by the upload.
    $previous = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://example.test/old.png',
        'is_active' => true,
        'source' => HeroVersionSource::AiGenerated,
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload("large_1024x512.png"))
        ->call('uploadHero', 'home', 'hero')
        ->assertHasNoErrors();

    $upload = HeroVersion::where('site_id', $site->id)
        ->where('source', HeroVersionSource::UserUpload->value)
        ->first();

    expect($upload)->not->toBeNull()
        ->and($upload->page_type)->toBe('home')
        ->and($upload->slot)->toBe('hero')
        ->and($upload->is_active)->toBeTrue()
        ->and($upload->url)->toContain('userupload')
        ->and($previous->fresh()->is_active)->toBeFalse();

    // S3 fake stores under the same path passed to put(). allFiles
    // recurses; one entry is enough proof the upload landed.
    $allFiles = Storage::disk('s3')->allFiles();
    expect($allFiles)->toHaveCount(1)
        ->and($allFiles[0])->toContain('userupload')
        ->and($allFiles[0])->toContain('previews/'.$site->id);
});

it('rejects an undersized image (<600px on long side) without creating a row', function () {
    $site = setupUploadSite();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload("small_300x300.png"))  // long edge 400 — below 600 floor
        ->call('uploadHero', 'home', 'hero');

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0);
});

it('rejects a non-image upload via Livewire validation', function () {
    $site = setupUploadSite();

    $pdf = UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\nfake pdf contents\n");

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', $pdf)
        ->call('uploadHero', 'home', 'hero')
        ->assertHasErrors(['heroUpload']);

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0);
});

it('uploads to the intro slot when slot=intro is passed', function () {
    $site = setupUploadSite();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload("large_1024x512.png"))
        ->call('uploadHero', 'home', 'intro')
        ->assertHasNoErrors();

    $upload = HeroVersion::where('site_id', $site->id)->first();
    expect($upload)->not->toBeNull()
        ->and($upload->slot)->toBe('intro')
        ->and($upload->source)->toBe(HeroVersionSource::UserUpload);
});

it('rejects unknown slot values', function () {
    $site = setupUploadSite();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload("large_1024x512.png"))
        ->call('uploadHero', 'home', 'banner')   // not hero / intro / band
        ->assertHasNoErrors();

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0);
});

function setupUploadSiteWithAboutAndService(): Site
{
    $site = setupUploadSite();

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => [],
        'sort_order' => 1,
        'version' => 1,
    ]);

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'roof-repairs',
        'content_data' => [],
        'sort_order' => 2,
        'version' => 1,
        'hero_source' => 'dedicated',
    ]);

    return $site;
}

it('uploads to the band slot when slot=band is passed', function () {
    $site = setupUploadSiteWithAboutAndService();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload('large_1024x512.png'))
        ->call('uploadHero', 'about', 'band')
        ->assertHasNoErrors();

    $upload = HeroVersion::where('site_id', $site->id)->first();
    expect($upload)->not->toBeNull()
        ->and($upload->slot)->toBe('band')
        ->and($upload->page_type)->toBe('about')
        ->and($upload->is_active)->toBeTrue()
        ->and($upload->source)->toBe(HeroVersionSource::UserUpload);
});

it('writes band uploads to band_images and never touches hero_images or intro_images', function () {
    $site = setupUploadSiteWithAboutAndService();
    $preview = $site->latestPreview;
    $preview->update([
        'snapshot' => [
            'hero_images' => ['about' => ['url' => 'https://example.test/hero.jpg']],
            'intro_images' => ['about' => ['url' => 'https://example.test/intro.jpg']],
        ],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload('large_1024x512.png'))
        ->call('uploadHero', 'about', 'band')
        ->assertHasNoErrors();

    $snapshot = $preview->fresh()->snapshot;

    expect($snapshot['hero_images']['about']['url'] ?? null)->toBe('https://example.test/hero.jpg')
        ->and($snapshot['intro_images']['about']['url'] ?? null)->toBe('https://example.test/intro.jpg')
        ->and($snapshot['band_images']['about']['url'] ?? null)->toContain('userupload')
        ->and($snapshot['band_images']['about']['source'] ?? null)->toBe('user_upload');
});

it('activateBandVersion flips the active band without touching hero or intro', function () {
    $site = setupUploadSiteWithAboutAndService();

    $hero = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'hero',
        'url' => 'https://example.test/hero.jpg',
        'is_active' => true,
        'source' => HeroVersionSource::AiGenerated,
    ]);
    $intro = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'intro',
        'url' => 'https://example.test/intro.jpg',
        'is_active' => true,
        'source' => HeroVersionSource::AiGenerated,
    ]);
    $band1 = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'band',
        'url' => 'https://example.test/band1.jpg',
        'is_active' => true,
        'source' => HeroVersionSource::UserUpload,
    ]);
    $band2 = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'band',
        'url' => 'https://example.test/band2.jpg',
        'is_active' => false,
        'source' => HeroVersionSource::UserUpload,
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('activateBandVersion', $band2->id)
        ->assertHasNoErrors();

    expect($band2->fresh()->is_active)->toBeTrue()
        ->and($band1->fresh()->is_active)->toBeFalse()
        ->and($hero->fresh()->is_active)->toBeTrue()
        ->and($intro->fresh()->is_active)->toBeTrue();
});

it('activateBandVersion ignores non-band versions', function () {
    $site = setupUploadSiteWithAboutAndService();

    $hero = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'hero',
        'url' => 'https://example.test/hero.jpg',
        'is_active' => true,
        'source' => HeroVersionSource::AiGenerated,
    ]);
    $intro = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'intro',
        'url' => 'https://example.test/intro.jpg',
        'is_active' => false,
        'source' => HeroVersionSource::AiGenerated,
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('activateBandVersion', $intro->id)
        ->assertHasNoErrors();

    expect($hero->fresh()->is_active)->toBeTrue()
        ->and($intro->fresh()->is_active)->toBeFalse();
});

it('shows the Band image card on about and service pages but not home', function () {
    $homeOnly = setupUploadSite();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $homeOnly->id])
        ->assertDontSee('Band image');

    $site = setupUploadSiteWithAboutAndService();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertSee('Band image');
});

it('uploads a band image for a service page', function () {
    $site = setupUploadSiteWithAboutAndService();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('heroUpload', makeFixtureUpload('large_1024x512.png'))
        ->call('uploadHero', 'roof-repairs', 'band')
        ->assertHasNoErrors();

    $upload = HeroVersion::where('site_id', $site->id)->first();
    expect($upload)->not->toBeNull()
        ->and($upload->slot)->toBe('band')
        ->and($upload->page_type)->toBe('roof-repairs')
        ->and($upload->is_active)->toBeTrue();
});

it('shows band version history when more than one band version exists', function () {
    $site = setupUploadSiteWithAboutAndService();

    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'band',
        'url' => 'https://example.test/band1.jpg',
        'is_active' => true,
        'source' => HeroVersionSource::UserUpload,
    ]);
    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'slot' => 'band',
        'url' => 'https://example.test/band2.jpg',
        'is_active' => false,
        'source' => HeroVersionSource::UserUpload,
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertSee('version history (2)');
});

it('rejects cross-slot version ids on every activation action', function () {
    $site = setupUploadSite();
    $mk = fn (string $slot, string $url) => HeroVersion::create([
        'site_id' => $site->id, 'page_type' => 'home', 'slot' => $slot,
        'url' => $url, 'is_active' => true,
        'source' => HeroVersionSource::AiGenerated,
    ]);
    $hero = $mk('hero', 'https://example.test/h.png');
    $intro = $mk('intro', 'https://example.test/i.png');
    $band = $mk('band', 'https://example.test/b.png');

    $c = Livewire::actingAs($this->staff)->test('page-manager', ['siteId' => $site->id]);
    $c->call('activateHeroVersion', $band->id);
    $c->call('activateHeroVersion', $intro->id);
    $c->call('activateIntroVersion', $band->id);
    $c->call('activateBandVersion', $hero->id);

    expect($hero->fresh()->is_active)->toBeTrue('hero untouched by cross-slot ids')
        ->and($intro->fresh()->is_active)->toBeTrue('intro untouched')
        ->and($band->fresh()->is_active)->toBeTrue('band untouched');
    $snapshot = $site->previews()->first()->snapshot ?? [];
    expect(json_encode($snapshot['hero_images'] ?? []))->not->toContain('b.png');
});

it('shows the Band image card on service pages and band flips isolate snapshot keys', function () {
    $site = setupUploadSiteWithAboutAndService();
    $servicePage = GeneratedPage::where('site_id', $site->id)
        ->where('page_type', '!=', 'home')->where('page_type', '!=', 'about')->first();

    $a = HeroVersion::create(['site_id' => $site->id, 'page_type' => $servicePage->page_type, 'slot' => 'band', 'url' => 'https://example.test/band-a.jpg', 'is_active' => true, 'source' => HeroVersionSource::AiGenerated]);
    $b = HeroVersion::create(['site_id' => $site->id, 'page_type' => $servicePage->page_type, 'slot' => 'band', 'url' => 'https://example.test/band-b.jpg', 'is_active' => false, 'source' => HeroVersionSource::AiGenerated]);

    $preview = $site->previews()->first();
    $before = $preview->snapshot ?? [];

    // Add a contact page so the negative arm is real, not vacuous.
    GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'contact',
        'content_data' => [], 'sort_order' => 9, 'version' => 1,
    ]);

    $c = Livewire::actingAs($this->staff)->test('page-manager', ['siteId' => $site->id]);
    // Discriminating card assertions: the per-page upload input id proves
    // the SERVICE page carries its own band card (about's copy of the
    // 'Band image' string can't satisfy this), and contact must not.
    $c->assertSee('id="band-upload-roof-repairs"', false);
    $c->assertDontSee('id="band-upload-contact"', false);
    $c->call('activateBandVersion', $b->id);

    expect($a->fresh()->is_active)->toBeFalse()
        ->and($b->fresh()->is_active)->toBeTrue();
    // Band flips deliberately write NO snapshot keys at all — the
    // renderer reads active slot='band' rows directly (documented at the
    // action). The isolation contract is therefore: snapshot unchanged.
    $after = $preview->fresh()->snapshot ?? [];
    expect($after)->toEqual($before);
});
