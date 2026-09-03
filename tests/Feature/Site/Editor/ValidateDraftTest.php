<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\FormDefinitionWriter;
use App\Services\Site\Editor\Operations\ValidateDraftOperation;
use App\Services\Site\HeroResolution;
use App\Services\Site\ThemeResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    Storage::fake('s3');
});

/**
 * @param  array<string, mixed>  $content
 * @param  list<array<string, mixed>>  $navItems
 * @param  array<string, mixed>  $siteAttributes
 * @param  array<string, mixed>  $theme
 * @return array{user: User, site: Site, page: GeneratedPage}
 */
function seedDraftSite(
    array $content,
    array $navItems = [],
    array $siteAttributes = [],
    string $pageType = 'home',
    array $theme = ['key' => 'trades-bold'],
): array {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(array_merge([
        'created_by_user_id' => $user->id,
    ], $siteAttributes));
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => $pageType,
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $composition = [
        'homepage_page_id' => $pageType === 'home' ? $page->id : null,
        'nav' => ['items' => $navItems],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => $theme,
    ];
    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);

    EditorSeeds::exposeAsInternal($site);

    return ['user' => $user, 'site' => $site->fresh(), 'page' => $page->fresh()];
}

function setDraftNav(Site $site, array $items, ?int $homepageId = null): void
{
    $draft = SiteDraft::query()->where('site_id', $site->id)->firstOrFail();
    $composition = $draft->composition ?? [];
    $composition['nav']['items'] = $items;
    if ($homepageId !== null) {
        $composition['homepage_page_id'] = $homepageId;
    }
    $draft->update(['composition' => $composition]);
}

function extraPage(Site $site, string $pageType, array $content, int $sortOrder = 1): GeneratedPage
{
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => $pageType,
        'content_data' => $content,
        'sort_order' => $sortOrder,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    return $page->fresh();
}

/**
 * @param  array<string, mixed>  $input
 * @return list<array<string, mixed>>
 */
function validateFindings(User $user, Site $site, array $input = []): array
{
    $result = EditorSeeds::run($user, $site, 'validate_draft', $input);
    expect($result->ok)->toBeTrue();

    return $result->data['findings'];
}

function replacePageContent(GeneratedPage $page, array $content): void
{
    PageRevision::query()->whereKey($page->published_revision_id)->update(['content_data' => $content]);
    $page->update(['content_data' => $content]);
}

function richLink(string $href): array
{
    return [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'Read more',
                'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
            ]],
        ]],
    ];
}

function variantSourceHasBypass(string $type, ?string $variant): bool
{
    $path = variantBladePath($type, $variant);
    if ($path === null) {
        return false;
    }

    $source = file_get_contents($path);
    if ($source === false) {
        return false;
    }

    $withoutFallbacks = preg_replace('/var\(\s*--[A-Za-z0-9_-]+\s*,\s*#[0-9A-Fa-f]{3,8}\s*\)/', '', $source) ?? $source;

    return preg_match('/#[0-9A-Fa-f]{3,8}\b/', $withoutFallbacks) === 1;
}

function variantBladePath(string $type, ?string $variant): ?string
{
    if (is_string($variant) && $variant !== '') {
        $file = resource_path("views/site/sections/variants/{$type}/{$variant}.blade.php");
        if (is_file($file)) {
            return $file;
        }
    }

    $stock = resource_path("views/site/sections/{$type}.blade.php");

    return is_file($stock) ? $stock : null;
}

function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $channel = function (string $pair): float {
        $value = hexdec($pair) / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    };
    $r = $channel(substr($hex, 0, 2));
    $g = $channel(substr($hex, 2, 2));
    $b = $channel(substr($hex, 4, 2));

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function independentContrastRatio(string $a, string $b): float
{
    $l1 = relativeLuminance($a);
    $l2 = relativeLuminance($b);

    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

it('declares validate_draft as a site-addressed read that does not require approval', function () {
    $operation = app(ValidateDraftOperation::class);

    expect($operation->name())->toBe('validate_draft')
        ->and($operation->readOnly())->toBeTrue()
        ->and($operation->address())->toBe('site')
        ->and($operation->wrapInAdminChange())->toBeFalse()
        ->and($operation->requiresApproval())->toBeFalse()
        ->and($operation->delegatesTo())->toBe([])
        ->and($operation->sideEffects())->toContain('some section variants paint literal colours a theme write cannot move');
});

it('reports broken_internal_link for an unresolved nav page and no other code', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    );
    setDraftNav($site, [
        ['type' => 'page', 'page_id' => $page->id, 'label' => 'Home'],
        ['type' => 'page', 'page_id' => 9_000_001, 'label' => 'Missing'],
    ], $page->id);
    $published = $page->published_revision_id;

    $findings = validateFindings($user, $site, ['checks' => ['broken_internal_link']]);

    expect($findings)->toBe([
        [
            'code' => 'broken_internal_link',
            'severity' => 'error',
            'path' => 'nav.items.1',
            'message' => 'Nav page id 9000001 does not resolve to a live page on this site.',
            'fix_hint' => 'Point the nav item at an existing, non-archived page of this site.',
        ],
    ]);
    expect($page->fresh()->published_revision_id)->toBe($published);
});

it('does not report a nav group href hash as a broken internal link', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    );
    setDraftNav($site, [
        ['type' => 'page', 'page_id' => $page->id, 'label' => 'Home'],
        [
            'type' => 'group',
            'label' => 'Services',
            'children' => [
                ['type' => 'page', 'page_id' => $page->id, 'label' => 'All services'],
            ],
        ],
    ], $page->id);

    expect(validateFindings($user, $site, ['checks' => ['broken_internal_link']]))->toBe([]);
});

it('reports missing_image for an unresolved media id and no other code', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite([
        'sections' => [
            ['type' => 'hero', 'title' => 'Home', 'background_image' => 8_888_888],
        ],
    ]);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'hero']);

    $findings = validateFindings($user, $site, ['checks' => ['missing_image']]);

    expect($findings)->toBe([
        [
            'code' => 'missing_image',
            'severity' => 'error',
            'page_id' => $page->id,
            'stored_index' => 0,
            'path' => 'background_image',
            'message' => 'Image field background_image does not resolve to media on this site.',
            'fix_hint' => 'Assign a site_media id that belongs to this site, or clear the field.',
        ],
    ]);
});

it('reports video_image_conflict only when video wins by the live flag without drafted mode on', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    );
    $image = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero.jpg',
    ]);
    $videoKey = 'dev-previews/'.$site->id.'/hero-home-video.mp4';
    Storage::disk('s3')->put($videoKey, 'video-bytes');
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => $videoKey,
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $image, $user->id);

    $resolved = app(HeroResolution::class)->for($site->fresh(), $page, true);
    expect($resolved->mode)->toBe('video');

    $findings = validateFindings($user, $site->fresh(), ['checks' => ['video_image_conflict']]);

    expect($findings)->toBe([
        [
            'code' => 'video_image_conflict',
            'severity' => 'warning',
            'page_id' => $page->id,
            'path' => 'hero',
            'message' => 'Draft-effective hero resolves to video by the live flag while an image is also selected, without a drafted hero_video mode=on.',
            'fix_hint' => 'Draft hero_video mode=on to keep video, or draft mode=off so the image wins.',
        ],
    ]);
});

it('does not report video_image_conflict when a drafted hero_video row is mode on beside a drafted image', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    );
    $image = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-hero.jpg',
    ]);
    $video = HeroVideoVersion::factory()->for($site)->create([
        's3_key' => 'drafts/intended-video.mp4',
    ]);
    Storage::disk('s3')->put($video->s3_key, 'video-bytes');
    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $image, $user->id);
    app(DraftAssetSelections::class)->setHeroVideo($site, $video, 'on', $user->id);

    $resolved = app(HeroResolution::class)->for($site->fresh(), $page, true);
    expect($resolved->mode)->toBe('video');

    expect(validateFindings($user, $site->fresh(), ['checks' => ['video_image_conflict']]))->toBe([]);
});

it('reports published_video_image_conflict as info for the live video-over-image state', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    );
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/live-hero.jpg',
    ]);
    $videoKey = 'dev-previews/'.$site->id.'/hero-home-video.mp4';
    Storage::disk('s3')->put($videoKey, 'video-bytes');
    $site->update([
        'home_hero_video_enabled' => true,
        'home_hero_video_path' => $videoKey,
    ]);

    $live = app(HeroResolution::class)->for($site->fresh(), $page, false);
    expect($live->mode)->toBe('video');

    $findings = validateFindings($user, $site->fresh(), ['checks' => ['published_video_image_conflict']]);

    expect($findings)->toBe([
        [
            'code' => 'published_video_image_conflict',
            'severity' => 'info',
            'page_id' => $page->id,
            'path' => 'hero',
            'message' => 'The live hero still plays video over an image until a human publishes.',
            'fix_hint' => 'Publish the draft to replace the live hero, or leave this as street state.',
        ],
    ]);
});

it('reports contrast_below_aa with finding severity error not receipt warn', function () {
    ['user' => $user, 'site' => $site] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        theme: [
            'key' => 'trades-bold',
            'text_override' => '#767676',
            'surface_override' => '#7a7a7a',
            'text_muted_override' => '#111111',
            'surface_alt_override' => '#ffffff',
            'primary_override' => '#1e40af',
            'accent_override' => '#f59e0b',
        ],
    );

    $tokens = app(ThemeResolver::class)->renderTokens(
        app(ThemeResolver::class)->resolve($site, [], [
            'text_override' => '#767676',
            'surface_override' => '#7a7a7a',
            'text_muted_override' => '#111111',
            'surface_alt_override' => '#ffffff',
            'primary_override' => '#1e40af',
            'accent_override' => '#f59e0b',
        ]),
    );
    $authorPairs = [
        ['text', 'surface', 4.5, 'error'],
        ['text_muted', 'surface', 4.5, 'error'],
        ['text_muted', 'surface_alt', 4.5, 'error'],
    ];
    $derivedPairs = [
        ['primary_text', 'surface', 4.5],
        ['accent_text', 'surface', 4.5],
        ['text_on_alt', 'surface_alt', 4.5],
        ['text_muted_on_alt', 'surface_alt', 3.0],
        ['primary_text_on_alt', 'surface_alt', 4.5],
        ['accent_text_on_alt', 'surface_alt', 4.5],
        ['text_on_band', 'band', 4.5],
        ['text_on_primary', 'primary', 4.5],
        ['text_on_accent', 'accent', 4.5],
        ['text_on_contrast', 'surface_contrast', 4.5],
        ['text_muted_on_contrast', 'surface_contrast', 4.5],
        ['accent_text_on_contrast', 'surface_contrast', 4.5],
    ];
    $expected = [];
    foreach ($authorPairs as [$fg, $bg, $floor, $severity]) {
        $ratio = independentContrastRatio($tokens[$fg], $tokens[$bg]);
        if ($ratio < $floor) {
            $expected[] = [
                'code' => 'contrast_below_aa',
                'severity' => $severity,
                'path' => $fg,
                'message' => "Token pair {$fg}/{$bg} is below WCAG AA.",
                'fix_hint' => 'Raise contrast between the author-controlled tokens, then re-check.',
            ];
        }
    }
    foreach ($derivedPairs as [$fg, $bg, $floor]) {
        $ratio = independentContrastRatio($tokens[$fg], $tokens[$bg]);
        if ($ratio < $floor) {
            $expected[] = [
                'code' => 'contrast_below_aa',
                'severity' => 'warning',
                'path' => $fg,
                'message' => "Token pair {$fg}/{$bg} is below WCAG AA.",
                'fix_hint' => 'Raise contrast between the author-controlled tokens, then re-check.',
            ];
        }
    }
    usort($expected, fn (array $a, array $b): int => $a['path'] <=> $b['path']);
    expect($expected)->not->toBe([]);

    $findings = validateFindings($user, $site, ['checks' => ['contrast_below_aa']]);
    foreach ($findings as $finding) {
        expect($finding['severity'])->toBeIn(['error', 'warning', 'info'])
            ->and($finding['severity'])->not->toBe('warn');
    }
    expect($findings)->toBe($expected);

    $result = EditorSeeds::run($user, $site, 'validate_draft', ['checks' => ['contrast_below_aa']]);
    expect($result->toArray()['receipt']['warnings'])->toBe([]);
});

it('reports alt_text_missing for blank site media alt text and no other code', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    );
    $media = SiteMedia::factory()->for($site)->create(['alt_text' => '']);
    replacePageContent($page, ['sections' => [
        ['type' => 'hero', 'title' => 'Home', 'background_image' => $media->id],
    ]]);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'hero']);

    $findings = validateFindings($user, $site, ['checks' => ['alt_text_missing']]);

    expect($findings)->toBe([
        [
            'code' => 'alt_text_missing',
            'severity' => 'warning',
            'page_id' => $page->id,
            'stored_index' => 0,
            'path' => 'background_image',
            'message' => "Media {$media->id} referenced by background_image has no alt text.",
            'fix_hint' => 'Set alt_text on the media row so the image is described.',
        ],
    ]);
});

it('reports invalid_form for a stored definition that check rejects and no other code', function () {
    $oversizeTitle = str_repeat('T', 61);
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite([
        'sections' => [
            ['type' => 'hero', 'title' => 'Home'],
            [
                'type' => 'contact_form',
                'title' => $oversizeTitle,
                'submit_label' => 'Send',
                'fields' => [],
            ],
        ],
    ]);

    $errors = app(FormDefinitionWriter::class)->check([
        'title' => $oversizeTitle,
        'submit_label' => 'Send',
        'fields' => [],
    ], 'contact_form');
    expect($errors)->not->toBe([]);

    $findings = validateFindings($user, $site, ['checks' => ['invalid_form']]);

    expect($findings)->toBe([
        [
            'code' => 'invalid_form',
            'severity' => 'error',
            'page_id' => $page->id,
            'stored_index' => 1,
            'path' => 'fields',
            'message' => 'Form definition failed validation.',
            'fix_hint' => 'Fix the stored form fields so they pass FormDefinitionWriter::check.',
        ],
    ]);
});

it('reports meta_description_long above 155 characters and no other code', function () {
    $description = str_repeat('d', 156);
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite([
        'sections' => [['type' => 'hero', 'title' => 'Home']],
        'meta' => ['seo' => ['meta_description' => $description, 'meta_title' => 'Short']],
    ]);

    $findings = validateFindings($user, $site, ['checks' => ['meta_description_long']]);

    expect($findings)->toBe([
        [
            'code' => 'meta_description_long',
            'severity' => 'error',
            'page_id' => $page->id,
            'path' => 'meta.seo.meta_description',
            'message' => 'Meta description is 156 characters; the limit is 155.',
            'fix_hint' => 'Shorten the meta description to 155 characters or fewer.',
        ],
    ]);
});

it('reports meta_title_long above 60 characters and no other code', function () {
    $title = str_repeat('t', 61);
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite([
        'sections' => [['type' => 'hero', 'title' => 'Home']],
        'meta' => ['seo' => ['meta_title' => $title, 'meta_description' => 'Short enough.']],
    ]);

    $findings = validateFindings($user, $site, ['checks' => ['meta_title_long']]);

    expect($findings)->toBe([
        [
            'code' => 'meta_title_long',
            'severity' => 'warning',
            'page_id' => $page->id,
            'path' => 'meta.seo.meta_title',
            'message' => 'Meta title is 61 characters; the limit is 60.',
            'fix_hint' => 'Shorten the meta title to 60 characters or fewer.',
        ],
    ]);
});

it('reports theme_token_bypass from one predicate over rendered variant sources', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite([
        'sections' => [
            ['type' => 'lead_form', 'title' => 'Quote', 'variant' => 'phone-ledger', 'extra_fields' => []],
            ['type' => 'lead_form', 'title' => 'Hero form', 'variant' => 'image-backed', 'extra_fields' => []],
            ['type' => 'native_reviews', 'title' => 'Reviews'],
        ],
    ]);

    expect(variantSourceHasBypass('lead_form', 'phone-ledger'))->toBeFalse()
        ->and(variantSourceHasBypass('lead_form', 'image-backed'))->toBeTrue()
        ->and(variantSourceHasBypass('native_reviews', null))->toBeTrue();

    $expected = [];
    foreach ([
        [1, 'lead_form', 'image-backed'],
        [2, 'native_reviews', null],
    ] as [$index, $type, $variant]) {
        $name = $variant === null ? $type : "{$type}/{$variant}";
        $expected[] = [
            'code' => 'theme_token_bypass',
            'severity' => 'warning',
            'page_id' => $page->id,
            'stored_index' => $index,
            'path' => $name,
            'message' => "Variant {$name} paints a colour literal a theme write cannot move.",
            'fix_hint' => 'Replace the literal with a theme token, or accept the stated limitation.',
        ];
    }

    $findings = validateFindings($user, $site, ['checks' => ['theme_token_bypass']]);

    expect($findings)->toBe($expected)
        ->and(collect($findings)->pluck('path')->all())->not->toContain('lead_form/phone-ledger');
});

it('reports unchecked_external_link without sending HTTP', function () {
    Http::fake();

    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite(
        ['sections' => [
            ['type' => 'hero', 'title' => 'Home', 'cta_url' => 'https://partner.example/join'],
            ['type' => 'intro', 'title' => 'About', 'body' => richLink('https://blog.example/post')],
        ]],
        [
            ['type' => 'external', 'label' => 'Facebook', 'url' => 'https://facebook.com/acme'],
        ],
        ['custom_domain' => 'acme.test', 'custom_domain_status' => 'active'],
    );

    $findings = validateFindings($user, $site, ['checks' => ['unchecked_external_link']]);

    expect($findings)->toBe([
        [
            'code' => 'unchecked_external_link',
            'severity' => 'info',
            'path' => 'nav.items.0',
            'message' => 'External link https://facebook.com/acme was not fetched.',
            'fix_hint' => 'Inspect the URL yourself; validate_draft never requests third-party hosts.',
        ],
        [
            'code' => 'unchecked_external_link',
            'severity' => 'info',
            'page_id' => $page->id,
            'stored_index' => 0,
            'path' => 'cta_url',
            'message' => 'External link https://partner.example/join was not fetched.',
            'fix_hint' => 'Inspect the URL yourself; validate_draft never requests third-party hosts.',
        ],
        [
            'code' => 'unchecked_external_link',
            'severity' => 'info',
            'page_id' => $page->id,
            'stored_index' => 1,
            'path' => 'body',
            'message' => 'External link https://blog.example/post was not fetched.',
            'fix_hint' => 'Inspect the URL yourself; validate_draft never requests third-party hosts.',
        ],
    ]);
    Http::assertNothingSent();
});

it('reports layout_not_checked once as info', function () {
    ['user' => $user, 'site' => $site] = seedDraftSite([
        'sections' => [['type' => 'hero', 'title' => 'Home']],
    ]);

    $findings = validateFindings($user, $site, ['checks' => ['layout_not_checked']]);

    expect($findings)->toBe([
        [
            'code' => 'layout_not_checked',
            'severity' => 'info',
            'message' => 'Overflow and mobile layout are not statically decidable from content_data.',
            'fix_hint' => 'Inspect desktop and mobile screenshots rather than trusting a static check.',
        ],
    ]);
});

it('orders findings by page id then stored index then code against an independently written list', function () {
    // About is inserted first (lower page id) then home. An unsorted walk still
    // visits pages by id, but two other axes diverge from the required order:
    // hero schema yields cta_url before background_image, so the same stored
    // index emits unchecked_external_link then missing_image; sorted order is
    // by code (missing_image then unchecked_external_link). layout_not_checked
    // is appended after the page walk with no page_id, so unsorted emits it
    // last while a missing page_id sorts first.
    ['user' => $user, 'site' => $site, 'page' => $about] = seedDraftSite(
        [
            'sections' => [
                [
                    'type' => 'hero',
                    'title' => 'About',
                    'cta_url' => 'https://outbound.example/x',
                    'background_image' => 7_000_001,
                ],
            ],
            'meta' => ['seo' => ['meta_title' => str_repeat('m', 61)]],
        ],
        pageType: 'about',
    );
    $home = extraPage($site, 'home', [
        'sections' => [
            ['type' => 'hero', 'title' => 'Home'],
            [
                'type' => 'contact_form',
                'title' => str_repeat('Q', 61),
                'submit_label' => 'Send',
                'fields' => [],
            ],
        ],
    ]);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'about', 'slot' => 'hero']);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'hero']);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => '__shared_service_hero', 'slot' => 'hero']);
    expect($about->id)->toBeLessThan($home->id);

    $checks = [
        'missing_image',
        'invalid_form',
        'unchecked_external_link',
        'meta_title_long',
        'layout_not_checked',
    ];
    $findings = validateFindings($user, $site, ['checks' => $checks]);

    expect($findings)->toBe([
        [
            'code' => 'layout_not_checked',
            'severity' => 'info',
            'message' => 'Overflow and mobile layout are not statically decidable from content_data.',
            'fix_hint' => 'Inspect desktop and mobile screenshots rather than trusting a static check.',
        ],
        [
            'code' => 'meta_title_long',
            'severity' => 'warning',
            'page_id' => $about->id,
            'path' => 'meta.seo.meta_title',
            'message' => 'Meta title is 61 characters; the limit is 60.',
            'fix_hint' => 'Shorten the meta title to 60 characters or fewer.',
        ],
        [
            'code' => 'missing_image',
            'severity' => 'error',
            'page_id' => $about->id,
            'stored_index' => 0,
            'path' => 'background_image',
            'message' => 'Image field background_image does not resolve to media on this site.',
            'fix_hint' => 'Assign a site_media id that belongs to this site, or clear the field.',
        ],
        [
            'code' => 'unchecked_external_link',
            'severity' => 'info',
            'page_id' => $about->id,
            'stored_index' => 0,
            'path' => 'cta_url',
            'message' => 'External link https://outbound.example/x was not fetched.',
            'fix_hint' => 'Inspect the URL yourself; validate_draft never requests third-party hosts.',
        ],
        [
            'code' => 'invalid_form',
            'severity' => 'error',
            'page_id' => $home->id,
            'stored_index' => 1,
            'path' => 'fields',
            'message' => 'Form definition failed validation.',
            'fix_hint' => 'Fix the stored form fields so they pass FormDefinitionWriter::check.',
        ],
    ]);

    $again = validateFindings($user, $site, ['checks' => $checks]);
    expect(json_encode($again))->toBe(json_encode($findings));
});

it('scopes the walk to page_id and rejects an unknown checks code as validation', function () {
    ['user' => $user, 'site' => $site, 'page' => $home] = seedDraftSite([
        'sections' => [['type' => 'hero', 'title' => 'Home']],
        'meta' => ['seo' => ['meta_title' => str_repeat('h', 61)]],
    ]);
    $about = extraPage($site, 'about', [
        'sections' => [['type' => 'hero', 'title' => 'About']],
        'meta' => ['seo' => ['meta_title' => str_repeat('a', 61)]],
    ]);

    $scoped = validateFindings($user, $site, [
        'page_id' => $about->id,
        'checks' => ['meta_title_long'],
    ]);
    expect($scoped)->toBe([
        [
            'code' => 'meta_title_long',
            'severity' => 'warning',
            'page_id' => $about->id,
            'path' => 'meta.seo.meta_title',
            'message' => 'Meta title is 61 characters; the limit is 60.',
            'fix_hint' => 'Shorten the meta title to 60 characters or fewer.',
        ],
    ])
        ->and(collect($scoped)->pluck('page_id')->all())->not->toContain($home->id);

    $unknown = EditorSeeds::run($user, $site, 'validate_draft', ['checks' => ['not_a_real_check']]);
    expect($unknown->ok)->toBeFalse()
        ->and($unknown->error['code'])->toBe('validation')
        ->and($unknown->data)->toBe([]);
});

it('keeps FormDefinitionWriter validate throwing on the same input it threw on before', function () {
    $writer = app(FormDefinitionWriter::class);
    $invalid = [
        'fields' => [['label' => 'Upload', 'type' => 'file']],
    ];

    try {
        $writer->validate($invalid, 'contact_form');
        test()->fail('validate() must still throw on invalid input');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'fields.0.type' => ['The selected fields.0.type is invalid.'],
        ]);
    }

    $valid = $writer->validate([
        'title' => 'Contact us',
        'submit_label' => 'Send',
        'fields' => [['label' => 'Job postcode', 'type' => 'text', 'required' => true]],
    ], 'contact_form');
    expect($valid['fields'][0]['name'])->toBe('job_postcode');
});

it('does not report the featured_products /shop CTA as a broken internal link on a shop site, but does on a shopless one', function () {
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Home'],
        ['type' => 'featured_products', 'title' => 'Featured products', 'source' => 'newest', 'count' => 4, 'cta_label' => 'Browse the shop', 'cta_url' => '/shop'],
    ]];

    ['user' => $user, 'site' => $site, 'page' => $page] = seedDraftSite($content);
    \App\Models\Shop\Product::factory()->published()->for($site)->create();
    setDraftNav($site, [['type' => 'page', 'page_id' => $page->id, 'label' => 'Home']], $page->id);
    expect($site->fresh()->hasEstablishedShop())->toBeTrue();
    expect(validateFindings($user, $site->fresh(), ['checks' => ['broken_internal_link']]))->toBe([]);

    ['user' => $user2, 'site' => $shopless, 'page' => $page2] = seedDraftSite($content, siteAttributes: ['custom_domain' => 'shopless.example']);
    setDraftNav($shopless, [['type' => 'page', 'page_id' => $page2->id, 'label' => 'Home']], $page2->id);
    $findings = validateFindings($user2, $shopless, ['checks' => ['broken_internal_link']]);
    expect($findings)->toHaveCount(1)->and($findings[0]['code'])->toBe('broken_internal_link');
});
