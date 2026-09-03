<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function slotPickerSite(array $siteAttrs = []): array
{
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id] + $siteAttrs);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);

    return [$agent, $site, $page];
}

/** Real PNG bytes via Imagick — the test container has no GD, so fake()->image() throws. */
function slotPickerPngUpload(string $name = 'photo.png', int $width = 1200, int $height = 800): UploadedFile
{
    $im = new Imagick;
    $im->newImage($width, $height, 'red');
    $im->setImageFormat('png');

    return UploadedFile::fake()->createWithContent($name, $im->getImageBlob());
}

function slotPickerGifUpload(string $name = 'a.gif'): UploadedFile
{
    $im = new Imagick;
    $im->newImage(16, 16, 'blue');
    $im->setImageFormat('gif');

    return UploadedFile::fake()->createWithContent($name, $im->getImageBlob());
}

it('select flips the active version within the slot only', function () {
    [$agent, $site, $page] = slotPickerSite();
    $a = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'band_2', 'is_active' => true]);
    $b = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'band_2', 'is_active' => false]);
    $hero = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero', 'is_active' => true]);

    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'band_2'])->call('select', $b->id);

    expect($a->fresh()->is_active)->toBeFalse()->and($b->fresh()->is_active)->toBeTrue()->and($hero->fresh()->is_active)->toBeTrue();
});

it('select on hero mirrors the version into the preview snapshot and bumps admin revision', function () {
    [$agent, $site, $page] = slotPickerSite();
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'hero_images' => [
                'home' => ['url' => 'https://example.test/old.jpg'],
            ],
        ],
    ]);
    HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
        'url' => 'https://example.test/old.jpg',
    ]);
    $next = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => false,
        'url' => 'https://example.test/new.jpg',
        'watermark_url' => 'https://example.test/new-wm.jpg',
        'prompt' => 'alt hero',
        'model' => 'demo-test',
        'placement' => ['text_zone' => 'bottom-left'],
    ]);

    Livewire::actingAs($agent)
        ->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero'])
        ->call('select', $next->id)
        ->assertDispatched('composition-dirty');

    $entry = $preview->fresh()->snapshot['hero_images']['home'];
    expect($entry['url'])->toBe('https://example.test/new.jpg')
        ->and($entry['watermark_url'])->toBe('https://example.test/new-wm.jpg')
        ->and($entry['prompt'])->toBe('alt hero')
        ->and($entry['model'])->toBe('demo-test')
        ->and($entry['text_zone'])->toBe('bottom-left');
    expect((int) SiteDraft::where('site_id', $site->id)->value('admin_revision'))->toBe(1);
});

it('select on a non-hero slot does not rewrite snapshot hero_images but still bumps admin revision', function () {
    [$agent, $site, $page] = slotPickerSite();
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'hero_images' => [
                'home' => ['url' => 'https://example.test/keep.jpg'],
            ],
        ],
    ]);
    $intro = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'intro',
        'is_active' => false,
        'url' => 'https://example.test/intro.jpg',
    ]);

    Livewire::actingAs($agent)
        ->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'intro'])
        ->call('select', $intro->id)
        ->assertDispatched('composition-dirty');

    expect($preview->fresh()->snapshot['hero_images']['home']['url'])->toBe('https://example.test/keep.jpg');
    expect((int) SiteDraft::where('site_id', $site->id)->value('admin_revision'))->toBe(1);
});

it('select refuses a foreign version id', function () {
    [$agent, $site, $page] = slotPickerSite();
    $foreign = HeroVersion::factory()->for(Site::factory()->create())->create(['page_type' => 'home', 'slot' => 'hero']);
    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero'])->call('select', $foreign->id)->assertStatus(404);
    expect($foreign->fresh()->is_active)->toBe($foreign->is_active);
});

it('upload re-encodes to webp and stores an inactive version; original bytes never stored', function () {
    Storage::fake('s3');
    [$agent, $site, $page] = slotPickerSite();
    $png = slotPickerPngUpload();
    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'intro'])
        ->set('file', $png)->call('upload');
    $row = HeroVersion::where('site_id', $site->id)->where('slot', 'intro')->firstOrFail();
    expect($row->is_active)->toBeFalse()->and($row->url)->toEndWith('.webp');
    $files = collect(Storage::disk('s3')->allFiles());
    expect($files->filter(fn ($f) => str_ends_with($f, '.png')))->toBeEmpty();
    $root = rtrim(config('services.storage.preview_root', 'previews'), '/');
    expect($files->first())->toStartWith("{$root}/{$site->id}/hero/home/intro/");
});

it('upload stores under services.storage.preview_root rather than a top-level hero prefix', function () {
    Storage::fake('s3');
    config(['services.storage.preview_root' => 'dev-previews']);
    [$agent, $site, $page] = slotPickerSite();
    $png = slotPickerPngUpload();

    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'intro'])
        ->set('file', $png)
        ->call('upload')
        ->assertHasNoErrors();

    $files = Storage::disk('s3')->allFiles();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toStartWith("dev-previews/{$site->id}/hero/home/intro/")
        ->and($files[0])->not->toStartWith('hero/');
});

it('upload is rate limited per site with the uploadHero key', function () {
    Storage::fake('s3');
    [$agent, $site, $page] = slotPickerSite();
    $key = "hero-upload:{$site->id}";
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 300);
    }

    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero'])
        ->set('file', slotPickerPngUpload())
        ->call('upload')
        ->assertHasErrors('file');

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0);
});

it('upload rejects images smaller than 600px on the long edge', function () {
    Storage::fake('s3');
    [$agent, $site, $page] = slotPickerSite();

    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero'])
        ->set('file', slotPickerPngUpload('tiny.png', 400, 300))
        ->call('upload')
        ->assertHasErrors('file');

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('upload rejects a page_type that is not a storage-key slug', function () {
    Storage::fake('s3');
    [$agent, $site, $page] = slotPickerSite();
    DB::table('generated_pages')->where('id', $page->id)->update(['page_type' => 'home/../etc']);

    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero'])
        ->set('file', slotPickerPngUpload())
        ->call('upload')
        ->assertStatus(404);

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('upload rejects gif and svg and oversize', function () {
    Storage::fake('s3');
    [$agent, $site, $page] = slotPickerSite();
    $t = Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'hero']);
    $t->set('file', slotPickerGifUpload())->call('upload')->assertHasErrors('file');
    $t->set('file', UploadedFile::fake()->create('a.svg', 10, 'image/svg+xml'))->call('upload')->assertHasErrors('file');
    $t->set('file', UploadedFile::fake()->create('big.jpg', 13 * 1024, 'image/jpeg'))->call('upload')->assertHasErrors('file');
});

it('upload reports a file error and stores nothing when object storage put fails', function () {
    $fake = Storage::fake('s3');
    $disk = \Mockery::mock($fake)->makePartial();
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::set('s3', $disk);

    [$agent, $site, $page] = slotPickerSite();
    $png = slotPickerPngUpload();

    Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'intro'])
        ->set('file', $png)
        ->call('upload')
        ->assertHasErrors('file');

    expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('upload deletes the stored object when hero version persistence fails', function () {
    Storage::fake('s3');
    [$agent, $site, $page] = slotPickerSite();
    $png = slotPickerPngUpload();

    $event = 'eloquent.creating: '.HeroVersion::class;
    HeroVersion::creating(fn () => throw new RuntimeException('forced persist failure'));

    try {
        Livewire::actingAs($agent)->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $page->id, 'slot' => 'intro'])
            ->set('file', $png)
            ->call('upload')
            ->assertHasErrors('file');

        expect(HeroVersion::where('site_id', $site->id)->count())->toBe(0)
            ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
    } finally {
        HeroVersion::getEventDispatcher()->forget($event);
    }
});

it('rejects a client write to versions and re-derives img src from the database', function () {
    [$agent, $site, $page] = slotPickerSite();
    $row = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'is_active' => true,
        'url' => 'https://cdn.example.test/real-hero.webp',
    ]);

    $component = Livewire::actingAs($agent)->test('image-slot-picker', [
        'siteId' => $site->id,
        'pageId' => $page->id,
        'slot' => 'hero',
    ]);

    expect($component->html())->toContain('src="'.$row->url.'"');
    expect(fn () => $component->set('versions', [[
        'id' => $row->id,
        'url' => 'https://evil.example/tampered.webp',
        'is_active' => true,
        'created_at' => '01 Jan 2026 00:00',
    ]]))->toThrow(CannotUpdateLockedPropertyException::class);
    expect($component->html())->toContain('src="'.$row->url.'"')
        ->and($component->html())->not->toContain('https://evil.example/tampered.webp');
});

it('page manager mounts at most one image slot picker for the selected page and slot', function () {
    [$agent, $site] = slotPickerSite();
    foreach (range(1, 4) as $i) {
        GeneratedPage::factory()->for($site)->create([
            'page_type' => 'service-'.$i,
            'kind' => PageKind::Service,
        ]);
    }

    $html = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->html();

    expect(substr_count($html, 'data-image-slot-pickers'))->toBe(1);
});

it('page manager mounts the image slot picker inside a service page after the tab is committed', function () {
    [$agent, $site] = slotPickerSite();
    GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing',
        'kind' => PageKind::Service,
    ]);

    $html = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->set('activeTab', 'plumbing')
        ->html();

    expect(substr_count($html, 'data-image-slot-pickers'))->toBe(1);

    $plumbingPanel = strstr($html, 'x-show="activePage === \'plumbing\'"');
    expect($plumbingPanel)->not->toBeFalse();
    $nextPanelAt = strpos($plumbingPanel, 'x-show="activePage === ', 1);
    $plumbingChunk = $nextPanelAt === false ? $plumbingPanel : substr($plumbingPanel, 0, $nextPanelAt);
    expect($plumbingChunk)->toContain('data-image-slot-pickers');

    $beforePlumbing = strstr($html, 'x-show="activePage === \'plumbing\'"', true);
    expect($beforePlumbing)->not->toContain('data-image-slot-pickers');

    $blade = file_get_contents(resource_path('views/livewire/page-manager.blade.php'));
    expect($blade)->toContain('wire:change="switchTab($event.target.value)"');
});

it('rejects a client write to page-manager imageSlot with a locked-property exception', function () {
    [$agent, $site] = slotPickerSite();

    $component = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id]);

    expect(fn () => $component->set('imageSlot', 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to mount or mutate an archived page, including a live replacement sharing the slug', function () {
    Queue::fake();
    Storage::fake('s3');
    [$agent, $site] = slotPickerSite();
    $archived = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing',
        'kind' => PageKind::Service,
        'status' => PageStatus::Archived,
    ]);
    expect($archived->fresh()->archived_at)->not->toBeNull();

    $live = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing',
        'kind' => PageKind::Service,
    ]);
    $active = HeroVersion::factory()->for($site)->create([
        'page_type' => 'plumbing',
        'slot' => 'hero',
        'is_active' => true,
        'url' => 'https://cdn.example.test/live.webp',
    ]);
    $inactive = HeroVersion::factory()->for($site)->create([
        'page_type' => 'plumbing',
        'slot' => 'hero',
        'is_active' => false,
        'url' => 'https://cdn.example.test/next.webp',
    ]);

    Livewire::actingAs($agent)
        ->test('image-slot-picker', ['siteId' => $site->id, 'pageId' => $archived->id, 'slot' => 'hero'])
        ->assertStatus(404);

    $selectStale = Livewire::actingAs($agent)->test('image-slot-picker', [
        'siteId' => $site->id, 'pageId' => $live->id, 'slot' => 'hero',
    ]);
    $uploadStale = Livewire::actingAs($agent)->test('image-slot-picker', [
        'siteId' => $site->id, 'pageId' => $live->id, 'slot' => 'hero',
    ]);
    $live->update(['status' => PageStatus::Archived]);
    expect($live->fresh()->archived_at)->not->toBeNull();

    $selectStale->call('select', $inactive->id)->assertStatus(404);
    $uploadStale->set('file', slotPickerPngUpload())->call('upload')->assertStatus(404);
    expect($active->fresh()->is_active)->toBeTrue()
        ->and($inactive->fresh()->is_active)->toBeFalse()
        ->and(HeroVersion::where('site_id', $site->id)->where('slot', 'hero')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('does not mount an image slot picker for an archived page in page-manager', function () {
    [$agent, $site] = slotPickerSite();
    $archived = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'old-plumbing',
        'kind' => PageKind::Service,
        'status' => PageStatus::Archived,
    ]);

    $html = Livewire::actingAs($agent)
        ->test('page-manager', ['siteId' => $site->id])
        ->html();

    expect($html)->not->toContain('image-slot-'.$archived->id);
});
