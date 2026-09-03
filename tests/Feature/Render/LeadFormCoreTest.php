<?php

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A published home page: hero first, then $sections. $profileData keys override the defaults.
 *
 * @return array{0: Site, 1: GeneratedPage}
 */
function leadFormCoreHome(array $sections, array $profileData = [], array $siteAttrs = [], array $themeComposition = []): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold'] + $siteAttrs);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => array_replace(
            ['contact' => ['phones' => ['0161 123 4567']], 'geo' => ['service_area' => 'Manchester']],
            $profileData,
        ),
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => array_merge([['type' => 'hero', 'title' => 'Welcome to Acme']], $sections)],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'page', 'label' => 'Home', 'page_id' => $page->id]]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => array_replace(['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null], $themeComposition),
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

it('renders the same editor markers as before the extraction', function () {
    [$site, $page] = leadFormCoreHome([[
        'type' => 'lead_form', 'title' => 'T', 'intro' => 'I', 'eyebrow' => 'E', 'submit_label' => 'Go',
        'benefits' => ['a', 'b'], 'extra_fields' => [],
    ]]);
    // emitFormMarkers needs admin-edit AND formPanel: true (PageRenderer::render(), seventh parameter)
    $html = app(PageRenderer::class)->render($site, $page->id, 'admin-edit', formPanel: true);
    foreach (['eyebrow', 'title', 'intro', 'submit_label', 'benefits.0', 'benefits.1'] as $field) {
        expect($html)->toContain('data-editable-field="'.$field.'"');
    }
    expect(substr_count($html, 'data-form-editable='))->toBe(1)
        ->and($html)->toContain('data-form-kind="lead_form"');
});

it('the core clamps to MAX_FIELDS operator fields and keeps message', function () {
    $fields = [];
    for ($i = 1; $i <= 11; $i++) {
        $fields[] = ['name' => "f{$i}", 'type' => 'text', 'label' => "F {$i}"];
    }
    $fields[] = ['name' => 'message', 'type' => 'textarea', 'label' => 'Message'];
    [$site, $page] = leadFormCoreHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => $fields]]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('name="f10"')->not->toContain('name="f11"')
        ->and(substr_count($html, 'name="message"'))->toBe(1);
});

it('renders the core standalone from the explicit include contract', function () {
    $html = view('site.partials.lead-form-core', [
        'fields' => [
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
        ],
        'messageFieldMigrated' => false,
        'formMarker' => '',
        'allowedTypes' => ['text', 'tel', 'date', 'select', 'radio', 'textarea'],
        'formId' => 'lf-1-2',
        'site' => null,
        'pageId' => 1,
        'sectionIndex' => 2,
        'pageType' => 'home',
        'emitMarkers' => false,
        'editor' => fn () => '',
        'submitLabel' => 'Send',
        'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
        'cardStyle' => 'border-top-color: var(--brand-accent);',
        'chrome' => ['input_style' => null, 'radio_style' => null, 'submit_style' => null, 'surface' => null],
        'layout' => 'stacked',
    ])->render();

    expect($html)->toContain('id="lf-1-2-name"')
        ->and($html)->toContain('name="phone"')
        ->and($html)->toContain('name="message"');
});

it('stacked tiles radios render option descriptions and auto-arrow submit', function () {
    $html = view('site.partials.lead-form-core', [
        'fields' => [
            ['name' => 'job', 'type' => 'radio', 'label' => 'Job', 'required' => true, 'options' => [
                ['value' => 'build', 'label' => 'Build', 'description' => 'New construction'],
                'Repair',
            ]],
        ],
        'messageFieldMigrated' => false,
        'formMarker' => '',
        'allowedTypes' => ['text', 'tel', 'date', 'select', 'radio', 'textarea'],
        'formId' => 'lf-1-2',
        'site' => null,
        'pageId' => 1,
        'sectionIndex' => 2,
        'pageType' => 'home',
        'emitMarkers' => false,
        'editor' => fn () => '',
        'submitLabel' => 'Send',
        'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
        'cardStyle' => 'border-top-color: var(--brand-accent);',
        'chrome' => ['input_style' => 'boxed', 'radio_style' => 'tiles', 'submit_style' => 'auto-arrow', 'surface' => null],
        'layout' => 'stacked',
    ])->render();

    expect($html)->toContain(\App\Services\Site\FormChrome::LEAD_RADIO_TILE)
        ->and($html)->toContain('value="build"')
        ->and($html)->toContain('>Build</span>')
        ->and($html)->toContain('<span class="text-xs opacity-70">New construction</span>')
        ->and($html)->toContain('value="Repair"')
        // Pre-fix array options were a TypeError from e(), not the literal value="Array".
        ->and($html)->not->toContain('value="Array"')
        ->and($html)->toContain(\App\Services\Site\FormChrome::SUBMIT_AUTO_ARROW)
        ->and($html)->toContain('<span aria-hidden="true">→</span>');
});

it('select options accept the same array shape the radios accept', function () {
    $html = view('site.partials.lead-form-core', [
        'fields' => [
            ['name' => 'job', 'type' => 'select', 'label' => 'Job', 'options' => [
                ['value' => 'build', 'label' => 'Build'],
            ]],
        ],
        'messageFieldMigrated' => false,
        'formMarker' => '',
        'allowedTypes' => ['text', 'tel', 'date', 'select', 'radio', 'textarea'],
        'formId' => 'lf-1-2',
        'site' => null,
        'pageId' => 1,
        'sectionIndex' => 2,
        'pageType' => 'home',
        'emitMarkers' => false,
        'editor' => fn () => '',
        'submitLabel' => 'Send',
        'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
        'cardStyle' => 'border-top-color: var(--brand-accent);',
        'chrome' => ['input_style' => null, 'radio_style' => null, 'submit_style' => null, 'surface' => null],
        'layout' => 'stacked',
    ])->render();

    expect($html)->toContain('<option value="build">Build</option>');
});

it('radio option labels are white on a panel-inverted surface', function () {
    $html = view('site.partials.lead-form-core', [
        'fields' => [
            ['name' => 'job', 'type' => 'radio', 'label' => 'Job', 'options' => ['Build', 'Repair']],
        ],
        'messageFieldMigrated' => false,
        'formMarker' => '',
        'allowedTypes' => ['text', 'tel', 'date', 'select', 'radio', 'textarea'],
        'formId' => 'lf-1-2',
        'site' => null,
        'pageId' => 1,
        'sectionIndex' => 2,
        'pageType' => 'home',
        'emitMarkers' => false,
        'editor' => fn () => '',
        'submitLabel' => 'Send',
        'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
        'cardStyle' => 'border-top-color: var(--brand-accent);',
        'chrome' => ['input_style' => 'boxed', 'radio_style' => null, 'submit_style' => null, 'surface' => 'panel-inverted'],
        'layout' => 'stacked',
    ])->render();

    expect($html)->toContain('text-sm text-white')
        ->and($html)->not->toContain('text-sm text-gray-800');
});

it('thank-you heading and copy invert on a panel-inverted surface', function () {
    $contract = [
        'fields' => [],
        'messageFieldMigrated' => false,
        'formMarker' => '',
        'allowedTypes' => ['text', 'tel', 'date', 'select', 'radio', 'textarea'],
        'formId' => 'lf-1-2',
        'site' => null,
        'pageId' => 1,
        'sectionIndex' => 2,
        'pageType' => 'home',
        'emitMarkers' => false,
        'editor' => fn () => '',
        'submitLabel' => 'Send',
        'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
        'cardStyle' => 'border-top-color: var(--brand-accent);',
        'layout' => 'stacked',
    ];

    $inverted = view('site.partials.lead-form-core', $contract + [
        'chrome' => ['input_style' => null, 'radio_style' => null, 'submit_style' => null, 'surface' => 'panel-inverted'],
    ])->render();

    expect($inverted)->toContain('text-2xl font-bold mb-2 text-white')
        ->and($inverted)->toContain('<p class="text-white">We\'ve received your message and will get back to you shortly.</p>')
        ->and($inverted)->not->toContain('text-white/80')
        ->and($inverted)->not->toContain('text-gray-900')
        ->and($inverted)->toContain('rgba(255,255,255,0.16)');

    $light = view('site.partials.lead-form-core', $contract + [
        'chrome' => ['input_style' => null, 'radio_style' => null, 'submit_style' => null, 'surface' => null],
    ])->render();

    expect($light)->toContain('text-gray-900')
        ->and($light)->toContain('var(--brand-primary)');
});

it('tile descriptions on panel-inverted are full white with no opacity', function () {
    $html = view('site.partials.lead-form-core', [
        'fields' => [
            ['name' => 'job', 'type' => 'radio', 'label' => 'Job', 'required' => true, 'options' => [
                ['value' => 'build', 'label' => 'Build', 'description' => 'New construction'],
            ]],
        ],
        'messageFieldMigrated' => false,
        'formMarker' => '',
        'allowedTypes' => ['text', 'tel', 'date', 'select', 'radio', 'textarea'],
        'formId' => 'lf-1-2',
        'site' => null,
        'pageId' => 1,
        'sectionIndex' => 2,
        'pageType' => 'home',
        'emitMarkers' => false,
        'editor' => fn () => '',
        'submitLabel' => 'Send',
        'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
        'cardStyle' => 'border-top-color: var(--brand-accent);',
        'chrome' => ['input_style' => 'boxed', 'radio_style' => 'tiles', 'submit_style' => null, 'surface' => 'panel-inverted'],
        'layout' => 'stacked',
    ])->render();

    expect($html)->toContain('<span class="text-xs text-white">New construction</span>')
        ->and($html)->not->toContain('<span class="text-xs opacity-70">New construction</span>');
});

it('inline-editorial on a light primary renders the core in a white card, and a dark primary does not', function () {
    $section = ['type' => 'lead_form', 'title' => 'Get in touch', 'extra_fields' => [], 'benefits' => ['Insured'], 'variant' => 'inline-editorial'];

    [$lightSite, $lightPage] = leadFormCoreHome([$section], [], [], ['primary_override' => '#f97316']);
    $light = app(PageRenderer::class)->render($lightSite, $lightPage->id);
    expect($light)->toContain('data-lead-form-variant="inline-editorial"')
        ->and($light)->toContain('class="bg-white p-7 md:p-9"')
        ->and($light)->toContain('border-radius: var(--radius-card); box-shadow: 0 20px 40px -16px rgba(0,0,0,0.25);')
        ->and($light)->not->toContain('border-white/70')
        ->and($light)->toContain('text-red-500')
        ->and($light)->not->toContain('class="text-red-50"');

    [$darkSite, $darkPage] = leadFormCoreHome([$section]);
    $dark = app(PageRenderer::class)->render($darkSite, $darkPage->id);
    expect($dark)->toContain('data-lead-form-variant="inline-editorial"')
        ->and($dark)->not->toContain('class="bg-white p-7 md:p-9"')
        ->and($dark)->toContain('border-white/70');
});

it('dispatches to a lead_form variant blade when one is stamped, and falls through when absent or unknown', function () {
    $dir = resource_path('views/site/sections/variants/lead_form');
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/zz-probe.blade.php', '<div data-probe="lead_form-variant">{{ $section["title"] }}</div>');
    try {
        [$site, $page] = leadFormCoreHome([['type' => 'lead_form', 'title' => 'Probe', 'extra_fields' => [], 'variant' => 'zz-probe']]);
        $html = app(PageRenderer::class)->render($site, $page->id);
        expect($html)->toContain('data-probe="lead_form-variant"')->toContain('input[data-flatpickr]')->not->toContain('bg-white p-7 md:p-9 border-t-4');
        [$site2, $page2] = leadFormCoreHome([['type' => 'lead_form', 'title' => 'Plain', 'extra_fields' => [], 'variant' => 'nope']]);
        expect(app(PageRenderer::class)->render($site2, $page2->id))->toContain('bg-white p-7 md:p-9 border-t-4')->not->toContain('data-probe');
    } finally {
        @unlink($dir.'/zz-probe.blade.php');
    }
});
