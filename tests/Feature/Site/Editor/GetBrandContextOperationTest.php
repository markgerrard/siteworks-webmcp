<?php

use App\Enums\AgentRole;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\BrandImageService;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\Operations\GetBrandContextOperation;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    Storage::fake('s3');

    $this->seedEditorSite = function (bool $withProfile = true): array {
        $user = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create([
            'created_by_user_id' => $user->id,
            'business_name' => 'Acme Roofing',
            'design_brief' => [
                'display_font' => 'fraunces',
                'body_font' => 'source-sans-3',
                'palette' => [
                    'primary' => '#005bb5',
                    'accent' => '#f59e0b',
                    'surface' => '#ffffff',
                    'surface_alt' => '#111827',
                    'text' => '#f9fafb',
                    'text_muted' => '#d1d5db',
                ],
            ],
        ]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'B'],
        ]];
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'content_data' => $content]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        if ($withProfile) {
            BusinessProfile::factory()->for($site)->create([
                'profile_data' => [
                    'summary' => 'Local roofing for Midlands homes.',
                    'tone' => 'friendly, trustworthy',
                ],
            ]);
        }

        return ['user' => $user, 'site' => $site->fresh(), 'page' => $page->fresh()];
    };

    $this->runEditor = function (User $user, Site $site): OperationResult {
        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, ActorChannel::Webmcp),
            'get_brand_context',
            [],
        );
    };
});

it('returns every top-level key with the exact slot specs and leaves the published revision unchanged', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-home-hero.jpg',
    ]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'about',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-about-hero.jpg',
    ]);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected-logo.png',
        'metadata' => ['reads_on_dark' => true],
    ]);

    $foreign = Site::factory()->create();
    HeroVersion::factory()->for($foreign)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/foreign-hero.jpg',
    ]);

    $result = ($this->runEditor)($user, $site);
    $page->refresh();

    expect($result->ok)->toBeTrue()
        ->and(array_keys($result->data))->toBe([
            'business_name',
            'summary',
            'tone',
            'palette',
            'fonts',
            'logo',
            'hero',
            'slots',
        ])
        ->and($result->data['business_name'])->toBe('Acme Roofing')
        ->and($result->data['summary'])->toBe('Local roofing for Midlands homes.')
        ->and($result->data['tone'])->toBe('friendly, trustworthy')
        ->and($result->data['palette'])->toBe(app(BrandImageService::class)->effectivePalette($site))
        ->and($result->data['fonts'])->toBe(['display' => 'fraunces', 'body' => 'source-sans-3'])
        ->and($result->data['logo'])->toBe([
            'url' => Storage::disk('s3')->url('logos/selected-logo.png'),
            'safe_on' => 'dark',
        ])
        ->and($result->data['hero'])->toBe([ // only pages that exist render a hero (the seed has just `home`)
            'home' => 'https://cdn.example/active-home-hero.jpg',
        ])
        ->and($result->data['slots'])->toBe(GetBrandContextOperation::SLOT_SPECS)
        ->and($result->data['slots'])->toBe([
            'hero' => ['aspect' => '16:9', 'min_width' => 1920],
            'section_image' => ['aspect' => '4:3', 'min_width' => 1200],
            'portrait' => ['aspect' => '1:1', 'min_width' => 800],
            'logo' => ['aspect' => 'free', 'min_width' => 512],
        ])
        ->and($result->data['hero'])->not->toHaveKey('https://cdn.example/foreign-hero.jpg')
        ->and($result->data['hero'])->not->toContain('https://cdn.example/foreign-hero.jpg')
        ->and($page->published_revision_id)->toBe($published)
        ->and($page->draft_revision_id)->toBeNull();
});

it('maps hero page_type to url and prefers a draft selection over the active version', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $activeHome = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-home-hero.jpg',
    ]);
    $draftHome = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-home-hero.jpg',
    ]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'about',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-about-hero.jpg',
    ]);

    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-shared-hero.jpg',
    ]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'emergency-plumbing',
        'slot' => 'hero',
        'url' => 'https://cdn.example/stale-dedicated-row-never-rendered.jpg',
    ]);
    foreach ([['about', 'dedicated'], ['emergency-plumbing', 'shared']] as [$type, $source]) {
        \App\Models\GeneratedPage::create([
            'site_id' => $site->id, 'page_type' => $type, 'hero_source' => $source, 'content_data' => ['sections' => []],
            'sort_order' => 2, 'version' => 1, 'status' => \App\Enums\PageStatus::Published,
        ]);
    }

    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draftHome, $user->id);

    $result = ($this->runEditor)($user, $site);

    // The hero each page RENDERS: home → draft selection; about (dedicated) → its row;
    // a shared-source service page → the shared hero, never its stale dedicated row.
    expect($result->ok)->toBeTrue()
        ->and($result->data['hero'])->toBe([
            '__shared_service_hero' => 'https://cdn.example/active-shared-hero.jpg',
            'home' => 'https://cdn.example/draft-home-hero.jpg',
            'about' => 'https://cdn.example/active-about-hero.jpg',
            'emergency-plumbing' => 'https://cdn.example/active-shared-hero.jpg',
        ])
        ->and($activeHome->fresh()->is_active)->toBeTrue()
        ->and($draftHome->fresh()->is_active)->toBeFalse()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('prefers a draft-selected logo concept over the selected row', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $selected = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected-logo.png',
        'metadata' => ['reads_on_dark' => true],
    ]);
    $draft = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/draft-logo.png',
        'metadata' => ['reads_on_dark' => false],
    ]);

    app(DraftAssetSelections::class)->setLogo($site, $draft, $user->id);

    $result = ($this->runEditor)($user, $site);

    expect($result->ok)->toBeTrue()
        ->and($result->data['logo'])->toBe([
            'url' => Storage::disk('s3')->url('logos/draft-logo.png'),
            'safe_on' => 'light',
        ])
        ->and($selected->fresh()->is_selected)->toBeTrue()
        ->and($draft->fresh()->is_selected)->toBeFalse()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('is read-only', function () {
    expect(app(GetBrandContextOperation::class)->readOnly())->toBeTrue();
});

it('returns null summary and tone when the site has no profile', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)(false);
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site);

    expect($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->data['business_name'])->toBe('Acme Roofing')
        ->and($result->data['summary'])->toBeNull()
        ->and($result->data['tone'])->toBeNull()
        ->and($result->data['slots'])->toBe(GetBrandContextOperation::SLOT_SPECS)
        ->and($result->data['logo'])->toBe(['url' => null, 'safe_on' => null])
        ->and($result->data['hero'])->toBe([])
        ->and($page->fresh()->published_revision_id)->toBe($published);
});
