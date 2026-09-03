<?php

use App\Enums\AgentRole;
use App\Enums\MutationSource;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\CompositionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function seedSiteForDesignPanel(array $siteOverrides = []): Site
{
    $site = Site::factory()->create(array_merge(['theme' => 'trades-bold'], $siteOverrides));
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => \App\Enums\PageStatus::Published,
    ]);
    $revision = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => [],
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $revision->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

function designPanelBriefFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'mood' => 'warm-traditional',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'relaxed',
        'spacing_density' => 'generous',
        'corner_style' => 'soft',
        'palette' => [
            'primary' => '#1f3a5f',
            'accent' => '#8b6b2f',
            'tertiary' => '#f4ede0',
            'surface' => '#ffffff',
            'surface_alt' => '#f8f5ee',
            'border' => '#e4ddcf',
            'text' => '#1a1a1a',
            'text_muted' => '#6b7280',
        ],
        'rationale' => 'Heritage-led palette and serif display fit the business tone.',
    ], $overrides);
}

beforeEach(function () {
    Cache::flush();
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

test('mount with no brief shows regenerate call-to-action', function () {
    $site = seedSiteForDesignPanel(['created_by_user_id' => $this->staff->id, 'design_brief' => null]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->assertSuccessful()
        ->assertSee('No design brief yet')
        ->assertSee('Regenerate design brief');
});

test('mount with brief shows mood tiles + font cards + palette swatches', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->assertSee('Live preview')
        ->assertSee('Warm traditional')       // mood tile label
        ->assertSee('Fraunces')               // font card label (display)
        ->assertSee('Source Sans 3')          // font card label (body)
        ->assertSee('Primary')                // palette swatch label
        ->assertSee('Tertiary')               // palette swatch label (new in 2.5)
        ->assertSee('Why these choices')      // rationale callout
        ->assertSee('Heritage-led palette');  // rationale text
});

test('selectDisplayFont writes display_font_override', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('selectDisplayFont', 'space-grotesk')
        ->assertDispatched('composition-dirty');

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme['display_font_override'])->toBe('space-grotesk');
});

test('selectBodyFont writes body_font_override', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('selectBodyFont', 'manrope');

    expect(SiteDraft::where('site_id', $site->id)->first()->composition['theme']['body_font_override'])->toBe('manrope');
});

test('selectHeadingScale / selectSpacingDensity / selectCornerStyle all write their respective overrides', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('selectHeadingScale', 'tight')
        ->call('selectSpacingDensity', 'compact')
        ->call('selectCornerStyle', 'rounded');

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme['heading_scale_override'])->toBe('tight');
    expect($theme['spacing_density_override'])->toBe('compact');
    expect($theme['corner_style_override'])->toBe('rounded');
});

test('selectDisplayScale and selectContainerWidth persist overrides', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->assertSee('Display scale')
        ->assertSee('Grand widens the shell and scales headings and spacing together. Individual settings still override.')
        ->assertSee('Container width')
        ->call('selectDisplayScale', 'grand')
        ->call('selectContainerWidth', 'wide')
        ->assertDispatched('composition-dirty');

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme['display_scale_override'])->toBe('grand')
        ->and($theme['container_width_override'])->toBe('wide');
});

// Regression: Site had no `siteDraft` relation, so the review read
// $site->siteDraft as null — overrides wrote to the DB but resolvedTokens()
// returned brief values, leaving the clicked card looking inactive.
test('resolvedTokens reflects override immediately after a card click', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('selectDisplayFont', 'space-grotesk')
        ->call('selectBodyFont', 'manrope')
        ->call('selectCornerStyle', 'rounded');

    $resolved = $component->instance()->resolvedTokens();
    expect($resolved['display_font'])->toBe('space-grotesk');
    expect($resolved['body_font'])->toBe('manrope');
    expect($resolved['corner_style'])->toBe('rounded');
});

test('editPaletteToken + applyPaletteEdit writes palette override', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('editPaletteToken', 'tertiary')
        ->set('editingHex', '#abcdef')
        ->call('applyPaletteEdit')
        ->assertSet('editingToken', null)
        ->assertDispatched('composition-dirty');

    expect(SiteDraft::where('site_id', $site->id)->first()->composition['theme']['tertiary_override'])->toBe('#abcdef');
});

test('applyPaletteEdit rejects an invalid hex', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('editPaletteToken', 'primary')
        ->set('editingHex', 'not-a-hex')
        ->call('applyPaletteEdit')
        ->assertHasErrors(['editingHex']);

    // No write happened
    $theme = SiteDraft::where('site_id', $site->id)->first()?->composition['theme'] ?? [];
    expect($theme)->not->toHaveKey('primary_override');
});

test('clearPaletteOverride removes a single override', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);
    $cs->updateThemeOverrides($draft, [
        'tertiary_override' => '#abcdef',
        'primary_override' => '#ff7300',
    ], MutationSource::Admin);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('clearPaletteOverride', 'tertiary');

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'];
    expect($theme)->not->toHaveKey('tertiary_override');
    expect($theme['primary_override'])->toBe('#ff7300'); // untouched
});

test('brand section segment renders and persists soft while bold clears the override', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->assertSee('Brand sections')
        ->assertSee('Bold')
        ->assertSee('Soft')
        ->call('selectBrandSectionScheme', 'soft');

    $theme = SiteDraft::where('site_id', $site->id)->firstOrFail()->composition['theme'];
    expect($theme['brand_section_scheme_override'] ?? null)->toBe('soft');

    $component->call('selectBrandSectionScheme', 'bold');
    $theme = SiteDraft::where('site_id', $site->id)->firstOrFail()->composition['theme'];
    expect($theme)->not->toHaveKey('brand_section_scheme_override');
});

test('resetOverrides clears all *_override keys but keeps design_brief', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);
    $cs->updateThemeOverrides($draft, [
        'primary_override' => '#ff7300',
        'body_font_override' => 'manrope',
    ], MutationSource::Admin);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('resetOverrides');

    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'] ?? [];
    expect($theme)->not->toHaveKey('primary_override');
    expect($theme)->not->toHaveKey('body_font_override');

    // Brief still present
    expect($site->fresh()->design_brief)->not->toBeNull();
    expect($site->fresh()->design_brief['mood'])->toBe('warm-traditional');
});

test('removeBrief clears design_brief + all overrides', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);
    $cs = app(CompositionService::class);
    $draft = $cs->getOrCreateDraft($site);
    $cs->updateThemeOverrides($draft, ['primary_override' => '#ff7300'], MutationSource::Admin);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('removeBrief');

    expect($site->fresh()->design_brief)->toBeNull();
    $theme = SiteDraft::where('site_id', $site->id)->first()->composition['theme'] ?? [];
    expect($theme)->not->toHaveKey('primary_override');
});

test('selectDisplayFont with font not in allowlist is a no-op', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->call('selectDisplayFont', 'comic-sans');

    $theme = SiteDraft::where('site_id', $site->id)->first()?->composition['theme'] ?? [];
    expect($theme)->not->toHaveKey('display_font_override');
});

test('actions re-verify site authorisation on each call', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
    ]);
    $staffWithoutAccess = User::factory()->staff(AgentRole::Agent)->create();

    // Unauthorised agent cannot mount, let alone call actions.
    Livewire::actingAs($staffWithoutAccess)
        ->test('design-panel', ['siteId' => $site->id])
        ->assertStatus(403);
});

// ─── Regenerate lifecycle (poll + timeout + guards) ───────────────────

test('share image panel warns when the logo is missing', function () {
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_url' => 'https://cdn.example/og.png',
    ]);

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->assertSee('Share image')
        ->assertSee('No logo selected');

    Livewire::actingAs($this->staff)
        ->test('design-panel', ['siteId' => $site->id])
        ->assertDontSee('Share image');
});

test('a custom share image upload wins over the generated card', function () {
    Storage::fake('s3');
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_url' => 'https://cdn.example/generated.png',
    ]);

    $image = new Imagick;
    $image->newImage(1200, 630, 'white');
    $image->setImageFormat('png');
    $upload = \Illuminate\Http\UploadedFile::fake()->createWithContent('og.png', $image->getImageBlob());

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->set('shareImageUpload', $upload)
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->brand_og_custom_path)->toBeString()
        ->and(basename($fresh->brand_og_custom_path))->toMatch('/^og-custom-[a-f0-9]{40}\.png$/')
        ->and($fresh->brand_og_custom_meta)->toMatchArray(['width' => 1200, 'height' => 630])
        ->and($fresh->ogImageUrl())->not->toBe('https://cdn.example/generated.png')
        ->and($fresh->ogImageUrl())->toBe(\App\Support\Site\SitePublicObject::url($fresh->brand_og_custom_path));
});

test('a non-card custom share upload stores its decoded width and height', function () {
    Storage::fake('s3');
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_url' => 'https://cdn.example/generated.png',
    ]);

    $image = new Imagick;
    $image->newImage(900, 900, 'white');
    $image->setImageFormat('png');
    $upload = \Illuminate\Http\UploadedFile::fake()->createWithContent('og.png', $image->getImageBlob());

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->set('shareImageUpload', $upload)
        ->assertHasNoErrors();

    expect($site->fresh()->brand_og_custom_meta)->toMatchArray(['width' => 900, 'height' => 900]);
});

test('custom share uploads are decoded and dimension checked without trusting the extension', function () {
    Storage::fake('s3');
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_custom_path' => 'sites/1/brand/previous.png',
    ]);
    Storage::disk('s3')->put('sites/1/brand/previous.png', 'previous', 'public');

    $tooSmall = new Imagick;
    $tooSmall->newImage(599, 315, 'white');
    $tooSmall->setImageFormat('png');

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->set('shareImageUpload', \Illuminate\Http\UploadedFile::fake()->createWithContent('small.png', $tooSmall->getImageBlob()))
        ->assertHasErrors(['shareImageUpload']);

    expect($site->fresh()->brand_og_custom_path)->toBe('sites/1/brand/previous.png')
        ->and(Storage::disk('s3')->exists('sites/1/brand/previous.png'))->toBeTrue();

    $jpeg = new Imagick;
    $jpeg->newImage(1200, 630, 'white');
    $jpeg->setImageFormat('jpeg');
    $bytes = $jpeg->getImageBlob();

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->set('shareImageUpload', \Illuminate\Http\UploadedFile::fake()->createWithContent('misleading.png', $bytes))
        ->assertHasNoErrors();

    expect(basename((string) $site->fresh()->brand_og_custom_path))->toBe('og-custom-'.sha1($bytes).'.jpg');
});

test('a failed custom share upload preserves the previous object and database pointer', function () {
    $fake = Storage::fake('s3');
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_custom_path' => 'sites/1/brand/previous.png',
    ]);
    $fake->put('sites/1/brand/previous.png', 'previous', 'public');

    $disk = Mockery::mock($fake)->makePartial();
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldNotReceive('delete');
    Storage::set('s3', $disk);

    $image = new Imagick;
    $image->newImage(1200, 630, 'white');
    $image->setImageFormat('png');

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->set('shareImageUpload', \Illuminate\Http\UploadedFile::fake()->createWithContent('replacement.png', $image->getImageBlob()))
        ->assertHasErrors(['shareImageUpload']);

    expect($site->fresh()->brand_og_custom_path)->toBe('sites/1/brand/previous.png')
        ->and($fake->exists('sites/1/brand/previous.png'))->toBeTrue();
});

test('regenerate share image writes a generated card without clearing a custom upload', function () {
    Storage::fake('s3');
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_custom_path' => 'sites/1/brand/og-custom.png',
    ]);
    Storage::disk('s3')->put('sites/1/brand/og-custom.png', 'custom', 'public');

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->call('regenerateShareImage')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->brand_og_url)->toBeString()->toContain('/brand/og-')
        ->and($fresh->brand_og_custom_path)->toBe('sites/1/brand/og-custom.png')
        ->and($fresh->ogImageUrl())->toBe(\App\Support\Site\SitePublicObject::url('sites/1/brand/og-custom.png'));
});

test('removing a custom share image clears stored dimensions', function () {
    Storage::fake('s3');
    $site = seedSiteForDesignPanel([
        'created_by_user_id' => $this->staff->id,
        'design_brief' => designPanelBriefFixture(),
        'brand_og_custom_path' => 'sites/1/brand/og-custom.png',
        'brand_og_custom_meta' => ['width' => 900, 'height' => 900],
    ]);
    Storage::disk('s3')->put('sites/1/brand/og-custom.png', 'custom', 'public');

    Livewire::actingAs($this->staff)
        ->test('share-image-panel', ['siteId' => $site->id])
        ->call('removeCustomShareImage')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->brand_og_custom_path)->toBeNull()
        ->and($fresh->brand_og_custom_meta)->toBeNull();
});
