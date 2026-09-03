<?php

use App\Enums\AgentRole;
use App\Models\HeroVersion;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\CompositionService;
use Livewire\Livewire;
use Tests\Support\EditorSeeds;

function previewHeroCopyStyleRevision(Site $site): int
{
    app(CompositionService::class)->ensureDraftRow($site, $site->created_by_user_id);

    return (int) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision');
}

it('snapshot preview stays plain until the live knob is boxed', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'hero_copy_style' => null,
    ]);
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'pages' => [
                'home' => [
                    'hero' => [
                        'heading' => 'Welcome to Acme',
                        'subheading' => 'Plumbing in Wigan',
                        'cta_label' => 'Get a quote',
                    ],
                ],
            ],
            'profile' => ['name' => 'Acme Plumbing'],
            'theme' => ['primary_color' => '#1e40af', 'accent_color' => '#f59e0b'],
            'layout' => 'one_page',
            'hero_images' => ['home' => 'https://example.test/hero.jpg'],
        ],
    ]);

    $before = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    expect($before)->toContain('Welcome to Acme')
        ->and($before)->not->toContain('data-hero-variant="boxed-left"')
        ->and($before)->not->toContain('background-color: color-mix(in srgb, var(--brand-primary)');

    $site->update(['hero_copy_style' => 'boxed']);

    $after = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    expect($after)->toContain('data-hero-variant="boxed-left"')
        ->and($after)->toContain('background-color: color-mix(in srgb, var(--brand-primary)');
});

it('boxed and panel render unequal non-scene heroes on /preview/{slug}', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'hero_copy_style' => 'panel',
    ]);
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'pages' => [
                'home' => [
                    'hero' => [
                        'heading' => 'Welcome to Acme',
                        'subheading' => 'Plumbing in Wigan',
                        'cta_label' => 'Get a quote',
                    ],
                ],
                'about' => [
                    'hero' => [
                        'heading' => 'About Acme',
                        'subheading' => 'Family firm',
                    ],
                ],
            ],
            'profile' => ['name' => 'Acme Plumbing'],
            'theme' => ['primary_color' => '#1e40af', 'accent_color' => '#f59e0b'],
            'layout' => 'one_page',
            'hero_images' => [
                'home' => 'https://example.test/hero.jpg',
                'about' => 'https://example.test/about.jpg',
            ],
        ],
    ]);

    $panel = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    $site->update(['hero_copy_style' => 'boxed']);

    $boxed = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    expect($boxed)->not->toBe($panel)
        ->and($boxed)->toContain('Welcome to Acme')
        ->and($boxed)->toContain('About Acme')
        ->and($boxed)->toContain('hero-copy-box')
        ->and($boxed)->toContain('max-width: 36rem')
        ->and($panel)->not->toContain('hero-copy-box')
        ->and($panel)->not->toContain('max-width: 36rem')
        ->and($panel)->toContain('min-height: 100%')
        ->and($boxed)->not->toContain('min-height: 100%');
});

it('boxed and panel scene heroes stay unequal on /preview/{slug} after stripping the variant token', function (string $overlay) {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'hero_copy_style' => 'panel',
        'home_hero_video_enabled' => false,
    ]);
    $heroVersion = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://example.test/slide-1.webp',
        'source' => 'user_upload',
        'is_active' => false,
    ]);
    $site->update([
        'home_hero_scene' => [
            'kind' => 'image',
            'slides' => [[
                'asset_type' => 'hero_version',
                'asset_id' => $heroVersion->id,
                'heading' => 'Slide 1',
                'subheading' => 'First view',
                'cta_label' => 'Get a quote',
                'text_zone' => 'middle-right',
                'dwell_secs' => 6,
            ]],
            'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
            'overlay_style' => $overlay,
        ],
    ]);
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'pages' => [
                'home' => [
                    'hero' => [
                        'heading' => 'Welcome to Acme',
                        'subheading' => 'Plumbing in Wigan',
                        'cta_label' => 'Get a quote',
                    ],
                ],
            ],
            'profile' => ['name' => 'Acme Plumbing'],
            'theme' => ['primary_color' => '#1e40af', 'accent_color' => '#f59e0b'],
            'layout' => 'one_page',
            'hero_images' => ['home' => 'https://example.test/hero.jpg'],
        ],
    ]);

    $panel = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    $site->update(['hero_copy_style' => 'boxed']);

    $boxed = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    $strip = fn (string $html): string => (string) preg_replace(
        '/data-hero-variant="(?:boxed|panel)-left"/',
        'data-hero-variant="TOKEN"',
        $html,
    );

    expect($strip($boxed))->not->toBe($strip($panel))
        ->and($boxed)->toContain('hero-copy-box')
        ->and($boxed)->toContain('max-width: 36rem')
        ->and($panel)->not->toContain('hero-copy-box')
        ->and($panel)->not->toContain('max-width: 36rem');
})->with(['panel', 'gradient', 'none']);

it('set_hero_copy_style boxed is visible on /preview/{slug} without rewriting snapshot pages', function () {
    config(['editor.operations.enabled' => true, 'editor.agent_tools.enabled' => true]);
    [$user, $site] = EditorSeeds::homeWithHero();
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'pages' => [
                'home' => [
                    'hero' => [
                        'heading' => 'Welcome',
                        'subheading' => 'Local trades',
                        'cta_label' => 'Get a quote',
                    ],
                ],
            ],
            'profile' => ['name' => $site->business_name],
            'theme' => ['primary_color' => '#1e40af', 'accent_color' => '#f59e0b'],
            'layout' => 'one_page',
            'hero_images' => ['home' => 'https://example.test/hero.jpg'],
        ],
    ]);
    $pagesBefore = $preview->snapshot['pages'];

    $result = EditorSeeds::run($user, $site, 'set_hero_copy_style', [
        'hero_copy_style' => 'boxed',
        'composition_revision' => previewHeroCopyStyleRevision($site),
    ]);

    expect($result->ok)->toBeTrue();

    $html = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    expect($html)->toContain('data-hero-variant="boxed-left"')
        ->and($preview->fresh()->snapshot['pages'])->toBe($pagesBefore);
});

it('setKnob boxed is visible on /preview/{slug} the same way public cache is busted', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $agent->id]);
    $preview = Preview::factory()->for($site)->create([
        'snapshot' => [
            'pages' => [
                'home' => [
                    'hero' => [
                        'heading' => 'Welcome to Acme',
                        'subheading' => 'Plumbing in Wigan',
                        'cta_label' => 'Get a quote',
                    ],
                ],
            ],
            'profile' => ['name' => 'Acme Plumbing'],
            'theme' => ['primary_color' => '#1e40af', 'accent_color' => '#f59e0b'],
            'layout' => 'one_page',
            'hero_images' => ['home' => 'https://example.test/hero.jpg'],
        ],
    ]);

    config(['site.public_cache_enabled' => true]);
    $counterKey = "site:{$site->id}:pubcache_counter";
    $before = (int) cache()->get($counterKey, 0);

    Livewire::actingAs($agent)
        ->test('header-style-settings', ['siteId' => $site->id])
        ->call('setKnob', 'hero_copy_style', 'boxed');

    expect($site->fresh()->hero_copy_style)->toBe('boxed')
        ->and((int) cache()->get($counterKey, 0))->toBeGreaterThan($before);

    $html = $this->get(route('preview.show', $preview->slug))->assertOk()->getContent();

    expect($html)->toContain('data-hero-variant="boxed-left"');
});
