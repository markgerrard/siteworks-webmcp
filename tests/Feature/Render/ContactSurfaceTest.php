<?php

use App\Enums\PageKind;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\FormChrome;
use App\Services\Site\PageRenderer;
use App\Services\Site\ThemeResolver;

/**
 * Published contact page. $themeComposition is merged into composition.theme
 * (same override path as the lead-form light-primary test).
 *
 * @param  array<string, mixed>  $themeComposition
 * @param  list<array<string, mixed>>|null  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeContactSurfacePage(array $themeComposition = [], ?array $sections = null): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Roofing',
        'theme' => 'trades-bold',
    ]);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'contact',
        'kind' => PageKind::Core,
    ]);

    $sections ??= [
        ['type' => 'details', 'title' => 'Get In Touch', 'items' => [
            ['label' => 'Email', 'value' => 'hello@acme.test'],
            ['label' => 'Phone', 'value' => '0161 123 4567'],
        ]],
        ['type' => 'contact_form', 'title' => 'Send Us a Message', 'submit_label' => 'Send'],
    ];

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => array_replace([
                'key' => 'trades-bold',
                'primary_override' => null,
                'accent_override' => null,
            ], $themeComposition),
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [
            ['page_id' => $page->id, 'revision_id' => $revision->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $page];
}

function renderContactSurface(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

/**
 * 54-nh surface is dark navy (#090a0c). ThemeResolver::isDarkSurface must be true.
 *
 * @return array<string, string>
 */
function nhDarkSurfaceOverride(): array
{
    $decoded = json_decode((string) file_get_contents(base_path('tests/fixtures/home-themes/demo-site-themes.json')), true);
    expect($decoded['54-nh']['surface_color'] ?? null)->toBeString();

    return ['surface_override' => $decoded['54-nh']['surface_color']];
}

it('a dark-surface contact page uses the surface token wrapper, white headings, and dark input chrome', function () {
    $override = nhDarkSurfaceOverride();
    expect(app(ThemeResolver::class)->isDarkSurface($override['surface_override']))->toBeTrue();

    [$site, $page] = makeContactSurfacePage($override);
    $html = renderContactSurface($site, $page);

    expect($html)->toContain('background-color: var(--color-surface); color: var(--color-text);')
        ->not->toContain('py-20 lg:py-24 bg-gray-50')
        ->toContain('text-3xl md:text-4xl font-extrabold text-white')
        ->toContain('color-mix(in srgb, #ffffff 6%, var(--color-surface))')
        ->toContain(FormChrome::BOXED_INPUT_DARK)
        ->toContain(FormChrome::BOXED_LABEL_DARK)
        ->not->toContain('bg-white rounded-lg shadow-md p-8 md:p-10 border-t-4')
        ->toContain('text-[10px] font-medium text-white/60 uppercase tracking-wide')
        ->toContain('font-bold text-white text-lg mb-5">Contact Details')
        ->toContain('class="block text-sm font-semibold text-white group-hover:underline break-all"')
        ->toContain('text-2xl font-bold text-white mb-2">Thank You!')
        ->toContain('<p class="text-white/80">We\'ve received your message and will get back to you shortly.</p>')
        ->toContain('x-show="error" x-text="error" x-cloak class="'.FormChrome::ERROR_DARK.'"')
        ->not->toContain('x-show="error" x-text="error" x-cloak class="'.FormChrome::ERROR_LIGHT.'"');
});

it('a light-surface contact page keeps the exact current strings', function () {
    [$site, $page] = makeContactSurfacePage();
    $html = renderContactSurface($site, $page);

    expect($html)->toContain('py-20 lg:py-24 bg-gray-50')
        ->toContain('bg-white rounded-lg shadow-md p-8 md:p-10 border-t-4')
        ->toContain('text-3xl md:text-4xl font-extrabold text-gray-900')
        ->toContain(FormChrome::BOXED_INPUT)
        ->not->toContain('background-color: var(--color-surface); color: var(--color-text);')
        ->not->toContain('color-mix(in srgb, #ffffff 6%, var(--color-surface))')
        ->toContain('text-[10px] font-medium text-gray-400 uppercase tracking-wide')
        ->toContain('font-bold text-gray-900 text-lg mb-5">Contact Details')
        ->toContain('class="block text-sm font-semibold text-gray-900 group-hover:underline break-all"')
        ->toContain('text-2xl font-bold text-gray-900 mb-2">Thank You!')
        ->toContain('<p class="text-gray-600">We\'ve received your message and will get back to you shortly.</p>')
        ->toContain('x-show="error" x-text="error" x-cloak class="'.FormChrome::ERROR_LIGHT.'"');
});

it('a dark-surface standalone contact_form follows the same surface treatment', function () {
    [$site, $page] = makeContactSurfacePage(nhDarkSurfaceOverride(), [
        ['type' => 'contact_form', 'title' => 'Send Us a Message', 'intro' => 'Drop us a line.', 'submit_label' => 'Send'],
    ]);
    $html = renderContactSurface($site, $page);

    expect($html)->toContain('background-color: var(--color-surface); color: var(--color-text);')
        ->not->toContain('class="py-20 lg:py-24 bg-white"')
        ->toContain('text-3xl md:text-4xl font-extrabold text-white')
        ->toContain(FormChrome::BOXED_INPUT_DARK)
        ->not->toContain('bg-white rounded-lg shadow-md p-8 md:p-10 border-t-4')
        ->toContain('text-center text-white/80 mb-8 prose max-w-none')
        ->toContain('Drop us a line.')
        ->toContain('text-2xl font-bold text-white mb-2">Thank You!')
        ->toContain('<p class="text-white/80">We\'ve received your message and will get back to you shortly.</p>')
        ->toContain('x-show="error" x-text="error" x-cloak class="'.FormChrome::ERROR_DARK.'"')
        ->not->toContain('x-show="error" x-text="error" x-cloak class="'.FormChrome::ERROR_LIGHT.'"');
});

it('a light-surface standalone contact_form keeps the exact current wrapper and card', function () {
    [$site, $page] = makeContactSurfacePage([], [
        ['type' => 'contact_form', 'title' => 'Send Us a Message', 'intro' => 'Drop us a line.', 'submit_label' => 'Send'],
    ]);
    $html = renderContactSurface($site, $page);

    expect($html)->toContain('class="py-20 lg:py-24 bg-white"')
        ->toContain('bg-white rounded-lg shadow-md p-8 md:p-10 border-t-4')
        ->toContain(FormChrome::BOXED_INPUT)
        ->not->toContain('background-color: var(--color-surface); color: var(--color-text);')
        ->toContain('text-center text-gray-600 mb-8 prose max-w-none')
        ->toContain('Drop us a line.')
        ->toContain('text-2xl font-bold text-gray-900 mb-2">Thank You!')
        ->toContain('<p class="text-gray-600">We\'ve received your message and will get back to you shortly.</p>')
        ->toContain('x-show="error" x-text="error" x-cloak class="'.FormChrome::ERROR_LIGHT.'"');
});
