<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\PageRenderer;

function defaultPromoTiles(): array
{
    return [
        [
            'heading' => 'Same-day delivery',
            'text' => 'Order by two and we send it today.',
            'cta_label' => 'Shop bouquets',
            'cta_url' => '/shop',
            'tone' => 'primary',
        ],
        [
            'heading' => 'Workshop visits',
            'text' => 'See the bench and pick a bunch.',
            'cta_label' => 'Book a visit',
            'cta_url' => '/contact',
            'tone' => 'accent',
        ],
        [
            'heading' => 'Seasonal clubs',
            'text' => 'A monthly box of what is in season.',
            'cta_label' => 'Join the club',
            'cta_url' => '/about',
            'tone' => 'soft',
        ],
    ];
}

function seedPromoTilesSite(array $section = [], string $pageType = 'home'): array
{
    $site = Site::factory()->create([
        'business_name' => 'Camino',
        'theme' => 'trades-bold',
        'custom_domain' => 'camino-promo-'.bin2hex(random_bytes(4)).'.example',
        'custom_domain_status' => 'active',
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome'],
            array_merge([
                'type' => 'promo_tiles',
                'eyebrow' => 'Offers',
                'title' => 'This season',
                'tiles' => defaultPromoTiles(),
            ], $section),
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $pageType === 'home' ? $page->id : null,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site->fresh(), $page->fresh()];
}

test('promo_tiles renders three panels with copy, CTAs and tone backgrounds', function () {
    [$site, $page] = seedPromoTilesSite();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Offers')
        ->and($html)->toContain('This season')
        ->and($html)->toContain('Same-day delivery')
        ->and($html)->toContain('Order by two and we send it today.')
        ->and($html)->toContain('Shop bouquets')
        ->and($html)->toContain('href="/shop"')
        ->and($html)->toContain('Workshop visits')
        ->and($html)->toContain('See the bench and pick a bunch.')
        ->and($html)->toContain('Book a visit')
        ->and($html)->toContain('href="/contact"')
        ->and($html)->toContain('Seasonal clubs')
        ->and($html)->toContain('A monthly box of what is in season.')
        ->and($html)->toContain('Join the club')
        ->and($html)->toContain('href="/about"')
        ->and($html)->toContain('background: var(--brand-primary)')
        ->and($html)->toContain('background: var(--brand-accent)')
        ->and($html)->toContain('background: var(--color-surface-alt)')
        ->and($html)->toContain('md:grid-cols-3');
});

test('promo_tiles drops a fourth tile, skips empty headings, and renders nothing below two', function () {
    [$site, $page] = seedPromoTilesSite([
        'tiles' => [
            ['heading' => '', 'text' => 'Skipped copy', 'cta_label' => 'Skip me', 'cta_url' => '/skip', 'tone' => 'primary'],
            ['heading' => 'First kept', 'text' => 'One', 'cta_label' => 'Go', 'cta_url' => '/one', 'tone' => 'primary'],
            ['heading' => 'Second kept', 'text' => 'Two', 'cta_label' => 'Go', 'cta_url' => '/two', 'tone' => 'accent'],
            ['heading' => 'Third kept', 'text' => 'Three', 'cta_label' => 'Go', 'cta_url' => '/three', 'tone' => 'soft'],
            ['heading' => 'Fourth dropped', 'text' => 'Four', 'cta_label' => 'Go', 'cta_url' => '/four', 'tone' => 'primary'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('First kept')
        ->and($html)->toContain('Second kept')
        ->and($html)->toContain('Third kept')
        ->and($html)->not->toContain('Fourth dropped')
        ->and($html)->not->toContain('Skipped copy')
        ->and($html)->not->toContain('href="/skip"')
        ->and($html)->not->toContain('href="/four"')
        ->and($html)->toContain('md:grid-cols-3');

    [$lonelySite, $lonelyPage] = seedPromoTilesSite([
        'title' => 'Lonely promo row',
        'tiles' => [
            ['heading' => 'Only one', 'text' => 'Alone', 'cta_label' => 'Go', 'cta_url' => '/only', 'tone' => 'primary'],
        ],
    ]);

    $lonely = app(PageRenderer::class)->render($lonelySite, $lonelyPage->id, mode: 'public');

    expect($lonely)->not->toContain('Lonely promo row')
        ->and($lonely)->not->toContain('Only one')
        ->and($lonely)->toContain('Welcome');

    [$emptySite, $emptyPage] = seedPromoTilesSite([
        'title' => 'Empty promo row',
        'tiles' => [],
    ]);

    $empty = app(PageRenderer::class)->render($emptySite, $emptyPage->id, mode: 'public');

    expect($empty)->not->toContain('Empty promo row')
        ->and($empty)->toContain('Welcome');
});

test('unknown promo_tiles tone renders as soft', function () {
    [$site, $page] = seedPromoTilesSite([
        'tiles' => [
            ['heading' => 'Neon panel', 'text' => 'Unknown tone', 'cta_label' => 'Go', 'cta_url' => '/neon', 'tone' => 'neon'],
            ['heading' => 'Soft twin', 'text' => 'Known soft', 'cta_label' => 'Go', 'cta_url' => '/soft', 'tone' => 'soft'],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Neon panel')
        ->and($html)->toContain('Soft twin')
        ->and($html)->toContain('background: var(--color-surface-alt)')
        ->and($html)->not->toContain('tone-neon')
        ->and($html)->toContain('md:grid-cols-2');
});

test('executable promo_tiles cta_url never reaches an href and a missing label renders no anchor', function () {
    [$site, $page] = seedPromoTilesSite([
        'tiles' => [
            [
                'heading' => 'Scripted tile',
                'text' => 'Bad url',
                'cta_label' => 'Do not click',
                'cta_url' => 'javascript:alert(1)',
                'tone' => 'primary',
            ],
            [
                'heading' => 'Label-less tile',
                'text' => 'No button',
                'cta_label' => '',
                'cta_url' => '/safe',
                'tone' => 'accent',
            ],
        ],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('Scripted tile')
        ->and($html)->toContain('Label-less tile')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('Do not click')
        ->and($html)->not->toContain('href="/safe"');
});

test('add_section promo_tiles works on home and about with documented fields and is refused on a service page', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $ops = app(EditorOperations::class);

    $homeContent = ['sections' => [
        ['type' => 'hero', 'title' => 'A'],
        ['type' => 'cta', 'title' => 'B'],
    ]];
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'content_data' => $homeContent,
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create(['content_data' => $homeContent]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $aboutContent = ['sections' => [['type' => 'story', 'title' => 'About us']]];
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'content_data' => $aboutContent,
        'status' => PageStatus::Published,
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create(['content_data' => $aboutContent]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $serviceContent = ['sections' => [['type' => 'intro', 'title' => 'Plumbing']]];
    $service = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'extensions',
        'content_data' => $serviceContent,
        'status' => PageStatus::Published,
    ]);
    $serviceRev = PageRevision::factory()->for($service, 'page')->create(['content_data' => $serviceContent]);
    $service->update(['published_revision_id' => $serviceRev->id]);

    $ctx = new EditorContext($user, $site, ActorChannel::Webmcp);

    $added = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'promo_tiles',
        'position' => 1,
        'revision_base' => $home->published_revision_id,
        'structure_epoch' => (int) $home->structure_epoch,
        'fields' => [
            'eyebrow' => 'Offers',
            'title' => 'This season',
        ],
    ]);

    expect($added->ok)->toBeTrue();

    $homeDraft = PageRevision::query()->findOrFail($home->fresh()->draft_revision_id);
    $section = collect($homeDraft->content_data['sections'])->firstWhere('type', 'promo_tiles');

    expect($section)->toMatchArray([
        'type' => 'promo_tiles',
        'eyebrow' => 'Offers',
        'title' => 'This season',
        'tiles' => [],
    ]);

    $tilesWrite = $ops->run($ctx, 'edit_field', [
        'page_id' => $home->id,
        'stored_index' => 1,
        'field_path' => 'tiles',
        'value' => [
            [
                'heading' => 'Same-day delivery',
                'text' => 'Order by two.',
                'cta_label' => 'Shop',
                'cta_url' => '/shop',
                'tone' => 'primary',
            ],
            [
                'heading' => 'Workshop visits',
                'text' => 'See the bench.',
                'cta_label' => 'Book',
                'cta_url' => '/contact',
                'tone' => 'soft',
            ],
        ],
        'revision_base' => $home->fresh()->draft_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
    ]);

    expect($tilesWrite->ok)->toBeTrue('tiles list must validate through edit_field the way features.items does');

    $nested = $ops->run($ctx, 'edit_field', [
        'page_id' => $home->id,
        'stored_index' => 1,
        'field_path' => 'tiles.0.heading',
        'value' => 'Same-day vans',
        'revision_base' => $home->fresh()->draft_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
    ]);

    expect($nested->ok)->toBeTrue();

    $aboutAdded = $ops->run($ctx, 'add_section', [
        'page_id' => $about->id,
        'type' => 'promo_tiles',
        'position' => 1,
        'revision_base' => $about->published_revision_id,
        'structure_epoch' => (int) $about->structure_epoch,
        'fields' => [
            'eyebrow' => 'Studio',
            'title' => 'Visit us',
        ],
    ]);

    expect($aboutAdded->ok)->toBeTrue();

    $second = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'promo_tiles',
        'position' => 2,
        'revision_base' => $home->fresh()->draft_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
    ]);
    expect($second->ok)->toBeTrue();

    $third = $ops->run($ctx, 'add_section', [
        'page_id' => $home->id,
        'type' => 'promo_tiles',
        'position' => 3,
        'revision_base' => $home->fresh()->draft_revision_id,
        'structure_epoch' => (int) $home->fresh()->structure_epoch,
    ]);
    expect($third->ok)->toBeFalse()->and($third->error['code'])->toBe('validation');

    $onService = $ops->run($ctx, 'add_section', [
        'page_id' => $service->id,
        'type' => 'promo_tiles',
        'position' => 1,
        'revision_base' => $service->published_revision_id,
        'structure_epoch' => (int) $service->structure_epoch,
    ]);
    expect($onService->ok)->toBeFalse()->and($onService->error['code'])->toBe('validation');
});

test('admin-edit render emits editor markers on promo_tiles fields', function () {
    [$site, $page] = seedPromoTilesSite();

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit');

    expect($html)->toContain('data-editable-section-type="promo_tiles"')
        ->and($html)->toContain('data-editable-field="eyebrow"')
        ->and($html)->toContain('data-editable-field="title"')
        ->and($html)->toContain('data-editable-field="tiles.0.heading"')
        ->and($html)->toContain('data-editable-field="tiles.0.text"')
        ->and($html)->toContain('data-editable-field="tiles.0.cta_label"');
});
