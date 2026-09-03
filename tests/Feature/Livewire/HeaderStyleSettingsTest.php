<?php

use App\Enums\AgentRole;
use App\Enums\LogoSize;
use App\Models\Client;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Models\SiteMediaUsage;
use App\Models\User;
use App\Services\Images\ImageOptimiserService;
use App\Services\Media\MediaAssignService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Real PNG bytes via Imagick — the container has no GD, so fake()->image() throws.
 */
function brandRowPng(int $width = 1200, int $height = 400, string $name = 'brand.png'): UploadedFile
{
    $im = new Imagick;
    $im->newImage($width, $height, 'red');
    $im->setImageFormat('png');

    return UploadedFile::fake()->createWithContent($name, $im->getImageBlob());
}

function centredChromeSiteForHeader(User $agent): Site
{
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'label' => 'Centred badge',
        'recipe' => [
            'schema_version' => 1,
            'layout' => 'centred',
            'top_bar' => 'off',
            'nav_row' => 'beneath',
            'nav_case' => 'caps',
            'logo_height' => 'md',
            'store_controls' => 'icons+labels',
            'sticky_shrink' => 'on',
            'brand_pattern' => 'swirl',
        ],
    ]);

    return $site;
}

it('agent can set a header background colour', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setHeaderBg', '#1A1A1C');

    expect($site->fresh()->header_bg)->toBe('#1a1a1c');
});

it('rejects malformed header colours without persisting', function (string $bad) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setHeaderBg', $bad);

    expect($site->fresh()->header_bg)->toBeNull();
})->with([
    'css injection' => 'red;url(x)',
    'short hex' => '#fff',
    'no hash' => '1a1a1c',
    'bad chars' => '#gggggg',
]);

it('white stores null so the platform default keeps flowing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id, 'header_bg' => '#1a1a1c']);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('resetToWhite');

    expect($site->fresh()->header_bg)->toBeNull();
});

it('setting the header colour invalidates the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setHeaderBg', '#222225');

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('a user without access to the site cannot mount or change its header', function () {
    $owner = User::factory()->staff(AgentRole::Agent)->create();
    $outsider = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $owner->id]);

    Livewire::actingAs($outsider)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertStatus(403);

    expect($site->fresh()->header_bg)->toBeNull();
});

it('agent can set the logo margin and it clamps to 12', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setLogoMargin', 5);
    expect($site->fresh()->logo_margin)->toBe(5);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setLogoMargin', 30);
    // Clamp: 2x12px inside the smallest 40px logo box still leaves a
    // visible mark; 30 would erase compact logos.
    expect($site->fresh()->logo_margin)->toBe(12);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setLogoMargin', -4);
    expect($site->fresh()->logo_margin)->toBe(0);
});

it('setting the logo margin invalidates the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('logo-size-settings', ['siteId' => $site->id])
        ->call('setLogoMargin', 6);

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('agent can persist chrome_layout via setChromeLayout and it flushes the cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    \App\Models\LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'chrome',
        'key' => 'centred-badge',
        'label' => 'Centred badge',
        'recipe' => [
            'schema_version' => 1,
            'layout' => 'centred',
            'top_bar' => 'off',
            'nav_row' => 'beneath',
            'nav_case' => 'caps',
            'logo_height' => 'md',
            'store_controls' => 'icons+labels',
            'sticky_shrink' => 'on',
        ],
    ]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertSeeHtml('aria-label="Header preset"')
        ->assertSee('Centred badge')
        ->call('setChromeLayout', 'centred-badge')
        ->assertSet('chromeLayout', 'centred-badge');

    expect($site->fresh()->chrome_layout)->toBe('centred-badge')
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('setChromeLayout rejects a key that is not in chrome options', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'not-a-preset')
        ->assertHasErrors(['chromeLayout']);

    expect($site->fresh()->chrome_layout)->toBe('classic');
});

it('agent can persist header_mode overlay via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'header_mode', 'overlay');

    expect($site->fresh()->header_mode)->toBe('overlay');
});

it('hero copy select options equal ChromeKnobs::HERO_COPY_STYLES', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $html = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->html();

    expect(preg_match('/aria-label="Hero copy"[^>]*>(.*?)<\/select>/s', $html, $m))->toBe(1);
    preg_match_all('/<option value="([^"]+)"/', $m[1], $opts);
    expect($opts[1])->toBe(\App\Support\ChromeKnobs::HERO_COPY_STYLES);
});

it('nav container select options equal the ChromeKnobs constants', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $html = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->html();

    expect(preg_match('/aria-label="Navigation container"[^>]*>(.*?)<\/select>/s', $html, $styleMatch))->toBe(1)
        ->and(preg_match('/aria-label="Navigation container fill"[^>]*>(.*?)<\/select>/s', $html, $fillMatch))->toBe(1);

    preg_match_all('/<option value="([^"]*)"/', $styleMatch[1], $styleOptions);
    preg_match_all('/<option value="([^"]*)"/', $fillMatch[1], $fillOptions);

    expect($styleOptions[1])->toBe(['', ...\App\Support\ChromeKnobs::NAV_CONTAINER_STYLES])
        ->and($fillOptions[1])->toBe(['', ...\App\Support\ChromeKnobs::NAV_CONTAINER_FILLS])
        ->and($styleMatch[1])->toContain('Inherit (recipe)')
        ->and($fillMatch[1])->toContain('Inherit (recipe)');
});

it('agents can persist nav container knobs and bust the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_container_style', 'pill')
        ->assertSet('navContainerStyle', 'pill')
        ->call('setKnob', 'nav_container_fill', 'glass')
        ->assertSet('navContainerFill', 'glass');

    expect($site->fresh()->nav_container_style)->toBe('pill')
        ->and($site->fresh()->nav_container_fill)->toBe('glass')
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('setKnob stores explicit nav container none and surface and ignores bogus values', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'nav_container_style' => 'plate',
        'nav_container_fill' => 'brand',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_container_style', 'none')
        ->call('setKnob', 'nav_container_fill', 'surface')
        ->call('setKnob', 'nav_container_style', 'rounded')
        ->call('setKnob', 'nav_container_fill', 'white');

    expect($site->fresh()->nav_container_style)->toBe('none')
        ->and($site->fresh()->nav_container_fill)->toBe('surface');
});

it('nav container inherit options write null so the recipe resolves again', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'nav_container_style' => 'plate',
        'nav_container_fill' => 'brand',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_container_style', '')
        ->assertSet('navContainerStyle', '')
        ->call('setKnob', 'nav_container_fill', '')
        ->assertSet('navContainerFill', '');

    expect($site->fresh()->nav_container_style)->toBeNull()
        ->and($site->fresh()->nav_container_fill)->toBeNull();
});

it('agent can persist hero_copy_style panel via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertSeeHtml("setKnob('hero_copy_style'")
        ->call('setKnob', 'hero_copy_style', 'panel');

    expect($site->fresh()->hero_copy_style)->toBe('panel');
});

it('setKnob hero_copy_style preset stores null so the default keeps flowing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'hero_copy_style' => 'boxed',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'hero_copy_style', 'preset');

    expect($site->fresh()->hero_copy_style)->toBeNull();
});

it('setKnob ignores a bogus hero_copy_style without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'hero_copy_style', 'banner');

    expect($site->fresh()->hero_copy_style)->toBeNull();
});

it('setKnob ignores a bogus header_mode without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'header_mode', 'bogus');

    expect($site->fresh()->header_mode)->toBeNull();
});

it('agent can persist overlay_glass floating via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'overlay_glass', 'floating');

    expect($site->fresh()->overlay_glass)->toBe('floating');
});

it('setKnob overlay_glass off stores null so the default keeps flowing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'overlay_glass' => 'always',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'overlay_glass', 'off');

    expect($site->fresh()->overlay_glass)->toBeNull();
});

it('setKnob ignores a bogus overlay_glass without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'overlay_glass', 'frosted');

    expect($site->fresh()->overlay_glass)->toBeNull();
});

it('renders the overlay glass select only when header mode is overlay', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertSee('Switch header mode to Overlay to set a frosted glass treatment.')
        ->assertDontSeeHtml("setKnob('overlay_glass'")
        ->call('setKnob', 'header_mode', 'overlay')
        ->assertSeeHtml("setKnob('overlay_glass'")
        ->assertSeeHtml('aria-label="Overlay glass"')
        ->assertSee('While floating')
        ->assertSee('Always')
        ->assertDontSee('Switch header mode to Overlay to set a frosted glass treatment.');
});

it('agent can persist a nav cta label and url', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setCta', 'Book', '/book');

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBe('Book')
        ->and($fresh->nav_cta_url)->toBe('/book');
});

it('rejects a protocol-relative cta url without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setCta', 'Book', '//evil')
        ->assertHasErrors(['nav_cta_url']);

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBeNull()
        ->and($fresh->nav_cta_url)->toBeNull();
});

it('rejects a footer motto longer than 120 characters', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setMotto', str_repeat('x', 121))
        ->assertHasErrors(['footer_motto']);

    expect($site->fresh()->footer_motto)->toBeNull();
});

it('agent can enable the footer logo', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setFooterLogo', true);

    expect($site->fresh()->footer_show_logo)->toBeTrue();
});

it('successful chrome writes bump the public page cache counter', function (string $method, array $args) {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call($method, ...$args);

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
})->with([
    'header_mode' => ['setKnob', ['header_mode', 'overlay']],
    'overlay_glass' => ['setKnob', ['overlay_glass', 'floating']],
    'right_action' => ['setKnob', ['right_action', 'cta']],
    'right_action phone_cta' => ['setKnob', ['right_action', 'phone_cta']],
    'right_action none' => ['setKnob', ['right_action', 'none']],
    'nav_cta_target' => ['setKnob', ['nav_cta_target', 'form']],
    'form_style' => ['setKnob', ['form_style', 'underline']],
    'accent_style' => ['setKnob', ['accent_style', 'italic']],
    'hero_copy_style' => ['setKnob', ['hero_copy_style', 'boxed']],
    'nav_container_style' => ['setKnob', ['nav_container_style', 'pill']],
    'nav_container_fill' => ['setKnob', ['nav_container_fill', 'glass']],
    'cta' => ['setCta', ['Book', '/book']],
    'motto' => ['setMotto', ['Building beyond the brief.']],
    'footer logo' => ['setFooterLogo', [true]],
    'clear cta' => ['clearCta', []],
]);

it('a client of another site is denied on every chrome mutator', function (string $method, array $args) {
    $ownerClient = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'client_id' => $ownerClient->id,
        'created_by_user_id' => $agent->id,
        'header_bg' => '#1a1a1c',
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
        'footer_motto' => 'Stay local.',
        'header_mode' => 'overlay',
        'overlay_glass' => 'floating',
        'footer_show_logo' => true,
        'business_name' => 'Owner Co',
    ]);
    $outsider = User::factory()->create([
        'role' => null,
        'client_id' => $otherClient->id,
    ]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id]);

    $this->actingAs($outsider);

    if ($method === 'saveCta') {
        $component->set('navCtaLabel', 'Enquire')->set('navCtaUrl', '/enquire');
    }

    $component->call($method, ...$args)->assertStatus(403);

    $fresh = $site->fresh();
    expect($fresh->header_bg)->toBe('#1a1a1c')
        ->and($fresh->nav_cta_label)->toBe('Book')
        ->and($fresh->nav_cta_url)->toBe('/book')
        ->and($fresh->footer_motto)->toBe('Stay local.')
        ->and($fresh->header_mode)->toBe('overlay')
        ->and($fresh->overlay_glass)->toBe('floating')
        ->and($fresh->footer_show_logo)->toBeTrue()
        ->and($fresh->business_name)->toBe('Owner Co');
})->with([
    'setHeaderBg' => ['setHeaderBg', ['#222225']],
    'resetToWhite' => ['resetToWhite', []],
    'setKnob' => ['setKnob', ['header_mode', 'solid']],
    'setKnob overlay_glass' => ['setKnob', ['overlay_glass', 'always']],
    'setKnob nav_cta_target' => ['setKnob', ['nav_cta_target', 'form']],
    'setKnob nav_container_style' => ['setKnob', ['nav_container_style', 'plate']],
    'setKnob nav_container_fill' => ['setKnob', ['nav_container_fill', 'brand']],
    'setCta' => ['setCta', ['Visit', '/visit']],
    'saveCta' => ['saveCta', []],
    'clearCta' => ['clearCta', []],
    'setMotto' => ['setMotto', ['Hijack.']],
    'setFooterLogo' => ['setFooterLogo', [false]],
    'removeBrandImage' => ['removeBrandImage', []],
    'selectBrandMedia' => ['selectBrandMedia', [1]],
    'setBrandImageOpacity' => ['setBrandImageOpacity', [20]],
    'setBrandImageFit' => ['setBrandImageFit', ['tile']],
    'useImagePattern' => ['useImagePattern', []],
    'setNavRowBg' => ['setNavRowBg', ['#222225']],
    'resetNavRowBg' => ['resetNavRowBg', []],
    'setNavRowPattern' => ['setNavRowPattern', ['dots']],
    'setNavRowImageOpacity' => ['setNavRowImageOpacity', [20]],
    'setNavRowImageFit' => ['setNavRowImageFit', ['tile']],
    'setNavRowImagePositionY' => ['setNavRowImagePositionY', [15]],
    'removeNavRowImage' => ['removeNavRowImage', []],
    'selectNavRowMedia' => ['selectNavRowMedia', [1]],
]);

it('a client of this site can persist chrome mutators', function (string $method, array $args, string $column, mixed $expected) {
    $client = Client::factory()->create();
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'client_id' => $client->id,
        'created_by_user_id' => $agent->id,
        'header_bg' => '#1a1a1c',
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);
    $clientUser = User::factory()->create([
        'role' => null,
        'client_id' => $client->id,
    ]);

    $component = Livewire::actingAs($clientUser)
        ->test('header-style-settings', ['siteId' => $site->id]);

    if ($method === 'saveCta') {
        $component->set('navCtaLabel', 'Enquire')->set('navCtaUrl', '/enquire')->call('saveCta');
    } else {
        $component->call($method, ...$args);
    }

    $component->assertOk();
    expect($site->fresh()->{$column})->toBe($expected);
})->with([
    'setHeaderBg' => ['setHeaderBg', ['#222225'], 'header_bg', '#222225'],
    'resetToWhite' => ['resetToWhite', [], 'header_bg', null],
    'setKnob' => ['setKnob', ['header_mode', 'overlay'], 'header_mode', 'overlay'],
    'setKnob overlay_glass' => ['setKnob', ['overlay_glass', 'always'], 'overlay_glass', 'always'],
    'setKnob nav_cta_target' => ['setKnob', ['nav_cta_target', 'form'], 'nav_cta_target', 'form'],
    'setKnob right_action phone_cta' => ['setKnob', ['right_action', 'phone_cta'], 'right_action', 'phone_cta'],
    'setKnob right_action none' => ['setKnob', ['right_action', 'none'], 'right_action', 'none'],
    'setKnob hero_copy_style' => ['setKnob', ['hero_copy_style', 'boxed'], 'hero_copy_style', 'boxed'],
    'setKnob nav_container_style' => ['setKnob', ['nav_container_style', 'pill'], 'nav_container_style', 'pill'],
    'setKnob nav_container_fill' => ['setKnob', ['nav_container_fill', 'pattern'], 'nav_container_fill', 'pattern'],
    'setCta' => ['setCta', ['Visit', '/visit'], 'nav_cta_url', '/visit'],
    'saveCta' => ['saveCta', [], 'nav_cta_label', 'Enquire'],
    'clearCta' => ['clearCta', [], 'nav_cta_url', null],
    'setMotto' => ['setMotto', ['Stay local.'], 'footer_motto', 'Stay local.'],
    'setFooterLogo' => ['setFooterLogo', [true], 'footer_show_logo', true],
]);

it('persists both cta drafts when the label is entered first then saved', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'cta')
        ->assertSeeHtml('wire:model="navCtaLabel"')
        ->assertSeeHtml('wire:model="navCtaUrl"')
        ->set('navCtaLabel', 'Book')
        ->set('navCtaUrl', '/book')
        ->call('saveCta')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBe('Book')
        ->and($fresh->nav_cta_url)->toBe('/book');
});

it('shows a visible cta url error, persists nothing, and retains the label draft', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'cta')
        ->set('navCtaLabel', 'Book')
        ->set('navCtaUrl', 'javascript:alert(1)')
        ->call('saveCta')
        ->assertHasErrors(['nav_cta_url'])
        ->assertSee('The CTA URL is not allowed.')
        ->assertSet('navCtaLabel', 'Book');

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBeNull()
        ->and($fresh->nav_cta_url)->toBeNull();
});

it('clears both persisted cta fields', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('clearCta');

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBeNull()
        ->and($fresh->nav_cta_url)->toBeNull();
});

it('renders the cta label validation error under the input', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'cta')
        ->call('setCta', str_repeat('x', 41), '/book')
        ->assertHasErrors(['nav_cta_label'])
        ->assertSee('The CTA label may not be greater than 40 characters.');
});

it('renders the footer motto validation error under the input', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setMotto', str_repeat('x', 121))
        ->assertHasErrors(['footer_motto'])
        ->assertSee('The footer motto may not be greater than 120 characters.');
});

it('setKnob ignores columns outside the chrome allowlist', function (string $column, mixed $attempt) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $before = $site->{$column};

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', $column, $attempt);

    expect($site->fresh()->{$column})->toBe($before);
})->with([
    'header_bg' => ['header_bg', '#000000'],
    'business_name' => ['business_name', 'x'],
]);

it('does not persist either cta field when a populated pair is rejected', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setCta', 'Hijack', '//evil')
        ->assertHasErrors(['nav_cta_url']);

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBe('Book')
        ->and($fresh->nav_cta_url)->toBe('/book');
});

it('locks chrome display props against client writes', function (string $prop, mixed $value) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id]);

    expect(fn () => $component->set($prop, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'siteId' => ['siteId', 999],
    'headerBg' => ['headerBg', '#000000'],
    'chromeIsCentred' => ['chromeIsCentred', true],
    'chromeUsesImagePattern' => ['chromeUsesImagePattern', true],
    'brandImageUrl' => ['brandImageUrl', 'https://example.test/x.webp'],
    'brandImageOpacity' => ['brandImageOpacity', 20],
    'brandImageFit' => ['brandImageFit', 'tile'],
    'navRowBg' => ['navRowBg', '#000000'],
    'navRowPattern' => ['navRowPattern', 'dots'],
    'navRowImageUrl' => ['navRowImageUrl', 'https://example.test/n.webp'],
    'navRowImageOpacity' => ['navRowImageOpacity', 20],
    'navRowImageFit' => ['navRowImageFit', 'tile'],
    'headerMode' => ['headerMode', 'overlay'],
    'overlayGlass' => ['overlayGlass', 'always'],
    'rightAction' => ['rightAction', 'cta'],
    'navCtaTarget' => ['navCtaTarget', 'form'],
    'formStyle' => ['formStyle', 'underline'],
    'accentStyle' => ['accentStyle', 'italic'],
    'footerMotto' => ['footerMotto', 'hijack'],
    'footerShowLogo' => ['footerShowLogo', true],
]);

it('allows client updates to cta draft properties', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->set('navCtaLabel', 'Book')
        ->set('navCtaUrl', '/book')
        ->assertSet('navCtaLabel', 'Book')
        ->assertSet('navCtaUrl', '/book');

    expect($site->fresh()->nav_cta_label)->toBeNull()
        ->and($site->fresh()->nav_cta_url)->toBeNull();
});

it('strips control characters from the cta label on write', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setCta', "Book\u{202E}", '/book');

    expect($site->fresh()->nav_cta_label)->toBe('Book');
});

it('rejects a cta label that is empty after stripping control characters', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setCta', "\u{202E}", '/book')
        ->assertHasErrors(['nav_cta_label']);

    expect($site->fresh()->nav_cta_label)->toBeNull()
        ->and($site->fresh()->nav_cta_url)->toBeNull();
});

it('strips control characters from the footer motto on write', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setMotto', "Local\u{200B} craft");

    expect($site->fresh()->footer_motto)->toBe('Local craft');
});

it('rejects a footer motto that is empty after stripping control characters', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setMotto', "\u{202E}")
        ->assertHasErrors(['footer_motto']);

    expect($site->fresh()->footer_motto)->toBeNull();
});


it('agent can persist nav_case upper via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_case', 'upper');

    expect($site->fresh()->nav_case)->toBe('upper');
});

it('agent can persist header_shrink off via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'header_shrink', 'off');

    expect($site->fresh()->header_shrink)->toBe('off');
});

it('agent can set, clamp and clear header padding', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)->test('header-style-settings', ['siteId' => $site->id])->call('setHeaderPadding', 6);
    expect($site->fresh()->header_padding)->toBe(6);
    Livewire::actingAs($agent)->test('header-style-settings', ['siteId' => $site->id])->call('setHeaderPadding', 99);
    expect($site->fresh()->header_padding)->toBe(24);
    Livewire::actingAs($agent)->test('header-style-settings', ['siteId' => $site->id])->call('setHeaderPadding', null);
    expect($site->fresh()->header_padding)->toBeNull();
});

it('mount reflects the stored nav_case', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id, 'nav_case' => 'upper']);
    Livewire::actingAs($agent)->test('header-style-settings', ['siteId' => $site->id])->assertSet('navCase', 'upper');
});

it('agent can persist header_fit tight via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'header_fit', 'tight');

    expect($site->fresh()->header_fit)->toBe('tight');
});

it('agent can persist overlay_inner_scale main via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'overlay_inner_scale', 'main');

    expect($site->fresh()->overlay_inner_scale)->toBe('main');
});

it('setKnob overlay_inner_scale overlay stores null so the default keeps flowing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'overlay_inner_scale' => 'main',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'overlay_inner_scale', 'overlay');

    expect($site->fresh()->overlay_inner_scale)->toBeNull();
});

it('setKnob ignores a bogus overlay_inner_scale without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'overlay_inner_scale', 'x');

    expect($site->fresh()->overlay_inner_scale)->toBeNull();
});

it('renders the inner pages floating bar select only in overlay mode when overlay_logo_size is set', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $withSize = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'header_mode' => 'overlay',
        'overlay_logo_size' => LogoSize::Large,
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $withSize->id])
        ->assertSeeHtml("setKnob('overlay_inner_scale'")
        ->assertSeeHtml('aria-label="Inner pages floating bar"')
        ->assertSee('Match floating logo (default)')
        ->assertSee('Standard (smaller)');

    $noSize = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'header_mode' => 'overlay',
    ]);
    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $noSize->id])
        ->assertDontSeeHtml("setKnob('overlay_inner_scale'");

    $solid = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'overlay_logo_size' => LogoSize::Large,
    ]);
    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $solid->id])
        ->assertDontSeeHtml("setKnob('overlay_inner_scale'");
});

it('agent can persist right_action phone_cta and none via setKnob', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'phone_cta');
    expect($site->fresh()->right_action)->toBe('phone_cta');

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'none');
    expect($site->fresh()->right_action)->toBe('none');

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'phone');
    expect($site->fresh()->right_action)->toBeNull();
});

it('setKnob ignores a bogus right_action without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'both');

    expect($site->fresh()->right_action)->toBeNull();
});

it('agent can persist nav_cta_target form via setKnob and url stores null', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_cta_target', 'form');
    expect($site->fresh()->nav_cta_target)->toBe('form');

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_cta_target', 'url');
    expect($site->fresh()->nav_cta_target)->toBeNull();
});

it('setKnob ignores a bogus nav_cta_target without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'nav_cta_target', 'anchor');

    expect($site->fresh()->nav_cta_target)->toBeNull();
});

it('shows the cta target select when the action includes a cta and hides the url input in form mode', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertDontSeeHtml('aria-label="CTA target"')
        ->call('setKnob', 'right_action', 'cta')
        ->assertSeeHtml('aria-label="CTA target"')
        ->assertSeeHtml('wire:model="navCtaUrl"')
        ->call('setKnob', 'nav_cta_target', 'form')
        ->assertDontSeeHtml('wire:model="navCtaUrl"')
        ->assertSee('Enquiry form on this page, else Contact');
});

it('saveCta in form mode persists the label without requiring a url', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'right_action', 'cta')
        ->call('setKnob', 'nav_cta_target', 'form')
        ->set('navCtaLabel', 'Quote me')
        ->call('saveCta')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->nav_cta_label)->toBe('Quote me')
        ->and($fresh->nav_cta_url)->toBeNull()
        ->and($fresh->nav_cta_target)->toBe('form');
});

it('hides the brand row background block on the classic header', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertDontSee('Brand row background');
});

it('shows the brand row background block on a centred chrome preset', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->assertSee('Brand row background')
        ->assertSee('Use image pattern');
});

it('stores an uploaded brand image, sets the path, and flushes the public cache', function () {
    Storage::fake('s3');
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->set('brandImage', brandRowPng())
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->brand_image_path)->toBeString()
        ->and($fresh->brand_image_path)->toStartWith('sites/'.$site->id.'/brand/')
        ->and(Storage::disk('s3')->exists($fresh->brand_image_path))->toBeTrue()
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('remove clears the brand image path', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);
    $site->update(['brand_image_path' => 'sites/'.$site->id.'/brand/existing.webp']);
    Storage::disk('s3')->put($site->brand_image_path, 'bytes', 'public');

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->call('removeBrandImage')
        ->assertHasNoErrors();

    expect($site->fresh()->brand_image_path)->toBeNull();
});

it('persists brand image opacity and fit', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->call('setBrandImageOpacity', 20)
        ->call('setBrandImageFit', 'tile');

    $fresh = $site->fresh();
    expect((int) $fresh->brand_image_opacity)->toBe(20)
        ->and($fresh->brand_image_fit)->toBe('tile');
});

it('rejects a 5 MB brand image upload', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->set('brandImage', UploadedFile::fake()->create('huge.png', 5120, 'image/png'))
        ->assertHasErrors(['brandImage']);

    expect($site->fresh()->brand_image_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('rejects a brand image narrower than 1200px', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->set('brandImage', brandRowPng(400, 200, 'narrow.png'))
        ->assertHasErrors(['brandImage']);

    expect($site->fresh()->brand_image_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('Use image pattern creates a bespoke chrome row and points chrome_layout at it', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->call('useImagePattern')
        ->assertHasNoErrors()
        ->assertSet('chromeLayout', 'centred-badge-image');

    $fresh = $site->fresh();
    $row = LayoutPreset::query()
        ->where('site_id', $site->id)
        ->where('page_kind', 'chrome')
        ->where('key', 'centred-badge-image')
        ->first();

    expect($fresh->chrome_layout)->toBe('centred-badge-image')
        ->and($row)->not->toBeNull()
        ->and($row->status)->toBe(LayoutPreset::STATUS_ACTIVE)
        ->and($row->recipe['brand_pattern'])->toBe('image')
        ->and($row->recipe['layout'])->toBe('centred');
});

it('optimises the brand image on upload at 2000px and quality 78', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    $this->mock(ImageOptimiserService::class, function ($mock): void {
        $mock->shouldReceive('optimise')
            ->once()
            ->with(\Mockery::type('string'), 2000, 78)
            ->andReturn([
                'bytes' => 'optimised-bytes',
                'extension' => 'webp',
                'width' => 1200,
                'height' => 400,
            ]);
    });

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->set('brandImage', brandRowPng())
        ->assertHasNoErrors();

    $path = $site->fresh()->brand_image_path;
    expect($path)->toEndWith('.webp')
        ->and(Storage::disk('s3')->get($path))->toBe('optimised-bytes');
});

it('rejects a brand image that is still over 600 KB after optimisation', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);

    $this->mock(ImageOptimiserService::class, function ($mock): void {
        $mock->shouldReceive('optimise')
            ->once()
            ->with(\Mockery::type('string'), 2000, 78)
            ->andReturn([
                'bytes' => str_repeat('x', (600 * 1024) + 1),
                'extension' => 'webp',
                'width' => 2000,
                'height' => 800,
            ]);
    });

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->set('brandImage', brandRowPng())
        ->assertHasErrors(['brandImage'])
        ->assertSee('600 KB');

    expect($site->fresh()->brand_image_path)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

it('picker selection writes brand_image_media_id, copies into brand/, and registers usage', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);
    $key = 'site-media/'.$site->id.'/courtyard.webp';
    Storage::disk('s3')->put($key, 'library-bytes');
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => $key, 'title' => 'Courtyard']);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->assertSeeLivewire('media.picker')
        ->call('selectBrandMedia', $media->id)
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->brand_image_media_id)->toBe($media->id)
        ->and($fresh->brand_image_path)->toStartWith('sites/'.$site->id.'/brand/')
        ->and(Storage::disk('s3')->get($fresh->brand_image_path))->toBe('library-bytes')
        ->and(SiteMediaUsage::query()->where('site_media_id', $media->id)->where('slot', 'brand_row')->exists())->toBeTrue();
});

it('picker selection skips the brand copy when the asset is already under the brand prefix', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);
    $key = 'sites/'.$site->id.'/brand/already.webp';
    Storage::disk('s3')->put($key, 'brand-bytes');
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => $key]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->call('selectBrandMedia', $media->id)
        ->assertHasNoErrors();

    expect($site->fresh()->brand_image_path)->toBe($key)
        ->and(Storage::disk('s3')->get($key))->toBe('brand-bytes');
});

it('remove releases the brand_row usage and nulls both brand image columns', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = centredChromeSiteForHeader($agent);
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => 'site-media/'.$site->id.'/yard.webp']);
    $path = 'sites/'.$site->id.'/brand/existing.webp';
    Storage::disk('s3')->put($path, 'bytes', 'public');
    $site->update(['brand_image_path' => $path, 'brand_image_media_id' => $media->id]);
    app(MediaAssignService::class)->assign($media, $site, 'brand_row');

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setChromeLayout', 'centred-badge')
        ->call('removeBrandImage')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->brand_image_path)->toBeNull()
        ->and($fresh->brand_image_media_id)->toBeNull()
        ->and(SiteMediaUsage::query()->where('slot', 'brand_row')->exists())->toBeFalse();
});

it('agent can persist shop_nav_style mega via setKnob and link stores null', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertSeeHtml("setKnob('shop_nav_style'")
        ->call('setKnob', 'shop_nav_style', 'mega');

    expect($site->fresh()->shop_nav_style)->toBe('mega');

    $component->call('setKnob', 'shop_nav_style', 'dropdown');
    expect($site->fresh()->shop_nav_style)->toBe('dropdown');

    $component->call('setKnob', 'shop_nav_style', 'link');
    expect($site->fresh()->shop_nav_style)->toBeNull();
});

it('setKnob ignores a bogus shop_nav_style without persisting', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'shop_nav_style', 'menu');

    expect($site->fresh()->shop_nav_style)->toBeNull();
});

it('shows a Nav row group below the brand-band controls', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertSeeHtml('data-nav-row-background')
        ->assertSee('Nav row')
        ->assertSeeHtml('setNavRowBg')
        ->assertSeeHtml('setNavRowPattern')
        ->assertSeeHtml('setNavRowAccentBorder');
});

it('agent can cycle the nav-row accent border modes', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowAccentBorder', 'on');

    expect($site->fresh()->nav_row_accent_border)->toBe('on');

    $component->call('setNavRowAccentBorder', 'no_hero');
    expect($site->fresh()->nav_row_accent_border)->toBe('no_hero');

    $component->call('setNavRowAccentBorder', 'sometimes');
    expect($site->fresh()->nav_row_accent_border)->toBe('no_hero');

    $component->call('setNavRowAccentBorder', 'off');
    expect($site->fresh()->nav_row_accent_border)->toBeNull();
});

it('agent can set and reset a nav-row background colour', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowBg', '#1A1A1C');

    expect($site->fresh()->nav_row_bg)->toBe('#1a1a1c');

    $component->call('resetNavRowBg');
    expect($site->fresh()->nav_row_bg)->toBeNull();
});

it('rejects malformed nav-row colours without persisting', function (string $bad) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowBg', $bad);

    expect($site->fresh()->nav_row_bg)->toBeNull();
})->with([
    'css injection' => 'red;url(x)',
    'short hex' => '#fff',
    'no hash' => '1a1a1c',
    'bad chars' => '#gggggg',
]);

it('agent can persist a nav-row pattern and image knobs', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowPattern', 'dots')
        ->call('setNavRowImageOpacity', 20)
        ->call('setNavRowImageFit', 'tile')
        ->call('setNavRowImagePositionY', 15);

    $fresh = $site->fresh();
    expect($fresh->nav_row_pattern)->toBe('dots')
        ->and((int) $fresh->nav_row_image_opacity)->toBe(20)
        ->and($fresh->nav_row_image_fit)->toBe('tile')
        ->and((int) $fresh->nav_row_image_position_y)->toBe(15);
});

it('none stores a null nav-row pattern so the default keeps flowing', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'nav_row_pattern' => 'swirl',
    ]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowPattern', 'none');

    expect($site->fresh()->nav_row_pattern)->toBeNull();
});

it('image controls appear only when the nav-row pattern is image', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->assertDontSeeHtml('data-nav-row-image-controls')
        ->call('setNavRowPattern', 'image')
        ->assertSeeLivewire('media.picker')
        ->assertSeeHtml('data-nav-row-image-controls');
});

it('picker selection writes nav_row_image_media_id and registers usage', function () {
    Storage::fake('s3');
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $key = 'site-media/'.$site->id.'/nav-row.webp';
    Storage::disk('s3')->put($key, 'library-bytes');
    $media = SiteMedia::factory()->for($site)->create(['s3_key' => $key, 'width' => 1600]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowPattern', 'image')
        ->call('selectNavRowMedia', $media->id)
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect($fresh->nav_row_image_media_id)->toBe($media->id)
        ->and($fresh->nav_row_image_path)->toStartWith('sites/'.$site->id.'/nav-row/')
        ->and(Storage::disk('s3')->get($fresh->nav_row_image_path))->toBe('library-bytes')
        ->and(SiteMediaUsage::query()->where('site_media_id', $media->id)->where('slot', 'nav_row')->exists())->toBeTrue();
});

it('setting the nav-row colour invalidates the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setNavRowBg', '#222225');

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});

it('shows an Announcement group below the nav-row group', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $html = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->html();

    $navPos = strpos($html, 'data-nav-row-background');
    $announcementPos = strpos($html, 'data-announcement-settings');

    expect($html)->toContain('data-announcement-settings')
        ->and($html)->toContain('Announcement')
        ->and($html)->toContain('setAnnouncementEnabled')
        ->and($navPos)->not->toBeFalse()
        ->and($announcementPos)->not->toBeFalse()
        ->and($navPos)->toBeLessThan($announcementPos);
});

it('agent can enable the announcement strip and persist messages, order, and colour', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setAnnouncementEnabled', true)
        ->call('addAnnouncementMessage', 'First florist line')
        ->call('addAnnouncementMessage', 'Second with a link', '/shop')
        ->call('moveAnnouncementMessage', 1, -1)
        ->call('setAnnouncementBg', '#1A1A1C');

    $fresh = $site->fresh();
    expect($fresh->announcement_enabled)->toBeTrue()
        ->and($fresh->announcement_messages)->toEqual([
            ['text' => 'Second with a link', 'url' => '/shop'],
            ['text' => 'First florist line'],
        ])
        ->and($fresh->announcement_bg)->toBe('#1a1a1c');

    $component->call('resetAnnouncementBg');
    expect($site->fresh()->announcement_bg)->toBeNull();

    $component->call('removeAnnouncementMessage', 0);
    expect($site->fresh()->announcement_messages)->toBe([
        ['text' => 'First florist line'],
    ]);
});

it('rejects a sixth announcement message and a text over 120 characters', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $component = Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setAnnouncementEnabled', true);

    foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $text) {
        $component->call('addAnnouncementMessage', $text)->assertHasNoErrors();
    }

    $component->call('addAnnouncementMessage', 'Six')->assertHasErrors(['announcement_messages']);
    $component->call('setAnnouncementMessageText', 0, str_repeat('x', 121))->assertHasErrors(['announcement_messages.0.text']);

    expect($site->fresh()->announcement_messages)->toBe([
        ['text' => 'One'],
        ['text' => 'Two'],
        ['text' => 'Three'],
        ['text' => 'Four'],
        ['text' => 'Five'],
    ]);
});

it('rejects a javascript announcement url without persisting it', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('addAnnouncementMessage', 'Unsafe', 'javascript:alert(1)')
        ->assertHasErrors(['announcement_messages.0.url']);

    expect($site->fresh()->announcement_messages)->toBeNull();
});

it('rejects malformed announcement colours without persisting', function (string $bad) {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setAnnouncementBg', $bad);

    expect($site->fresh()->announcement_bg)->toBeNull();
})->with([
    'css injection' => 'red;url(x)',
    'short hex' => '#fff',
    'no hash' => '1a1a1c',
    'bad chars' => '#gggggg',
]);

it('setting the announcement colour invalidates the public page cache', function () {
    config(['site.public_cache_enabled' => true]);
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);

    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setAnnouncementEnabled', true);

    expect((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);
});
