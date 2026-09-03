<?php

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

function underlineFormsNormalise(string $html): string
{
    $html = preg_replace('/name="_token" value="[^"]+"/', 'name="_token" value="X"', $html) ?? $html;
    $html = preg_replace('/\?v=\d+/', '?v=N', $html) ?? $html;
    $html = preg_replace('/build\/assets\/[a-z0-9-]+\.[a-f0-9]{8}\./', 'build/assets/X.', $html) ?? $html;
    $html = preg_replace('/build\/assets\/[A-Za-z0-9._-]+/', 'build/assets/X', $html) ?? $html;
    $html = preg_replace('/\b(lf|cf)-\d+-\d+/', '$1-N-N', $html) ?? $html;

    return preg_replace('/wire:id="[^"]+"/', 'wire:id="X"', $html) ?? $html;
}

/**
 * @param  array<string, mixed>  $siteAttrs
 * @param  array<string, mixed>  $profile
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeUnderlineFormsPage(string $pageType, array $siteAttrs, array $profile, array $sections): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Roofing', 'theme' => 'trades-bold'] + $siteAttrs);

    $footer = ['columns' => [], 'show_credit' => true];
    if (isset($profile['footer']) && is_array($profile['footer'])) {
        $footer = array_merge($footer, $profile['footer']);
        unset($profile['footer']);
    }

    if ($pageType === 'roofing' && ($profile['home_lead_form_enabled'] ?? false) === true) {
        $profile['lead_form_policy'] = 'home_services';
    }

    if ($profile !== []) {
        BusinessProfile::factory()->for($site)->create(['profile_data' => $profile]);
    }

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => $pageType === 'roofing' ? PageKind::Service : PageKind::Core,
    ]);

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => $footer,
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [
            ['page_id' => $page->id, 'revision_id' => $page->published_revision_id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site->fresh(), $page->fresh()];
}

/**
 * @return list<string>
 */
function underlineFormChunks(string $html): array
{
    preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $matches);

    return $matches[0];
}

function assertUnderlineFormChrome(string $html): void
{
    $forms = underlineFormChunks($html);
    expect($forms)->not->toBeEmpty();

    foreach ($forms as $form) {
        expect(substr_count($form, 'border-gray-300'))->toBe(0);

        preg_match_all('/<(input|textarea|select)\b[^>]*>/i', $form, $tags);
        $controls = 0;
        foreach ($tags[0] as $tag) {
            if (preg_match('/\btype="hidden"/i', $tag) === 1) {
                continue;
            }
            if (preg_match('/\bname="website"/i', $tag) === 1) {
                continue;
            }
            if (preg_match('/\btype="radio"/i', $tag) === 1) {
                continue;
            }
            $controls++;
            expect($tag)->toContain('border-b');
        }
        expect($controls)->toBeGreaterThan(0);
    }
}

it('underline contact page (details + contact_form) has no boxed chrome in forms', function () {
    [$site, $page] = makeUnderlineFormsPage('contact', ['form_style' => 'underline'], [], [
        ['type' => 'details', 'title' => 'Contact', 'items' => [['label' => 'Email', 'value' => 'hello@acme.test']]],
        ['type' => 'contact_form', 'title' => 'Get in touch', 'fields' => [
            ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
            ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
            ['name' => 'when', 'type' => 'date', 'label' => 'When'],
        ]],
    ]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    assertUnderlineFormChrome($html);
    expect($html)->toContain('tracking-[0.18em]')
        ->toContain('border-gray-500');
});

it('underline service page lead_form has no boxed chrome in forms', function () {
    [$site, $page] = makeUnderlineFormsPage(
        'roofing',
        ['form_style' => 'underline'],
        ['home_lead_form_enabled' => true],
        [
            ['type' => 'hero', 'title' => 'Roofing', 'accent_word' => 'Roofing'],
            ['type' => 'lead_form', 'title' => 'Quote', 'extra_fields' => [
                ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
                ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
                ['name' => 'when', 'type' => 'date', 'label' => 'When'],
            ]],
        ],
    );

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    assertUnderlineFormChrome($html);
    expect($html)->toContain('tracking-[0.18em]')
        ->toContain('border-gray-500');
});

it('null form_style contact page matches the Task 0 fixture', function () {
    [$site, $page] = makeUnderlineFormsPage('contact', [], [], [
        ['type' => 'details', 'title' => 'Contact', 'items' => [['label' => 'Email', 'value' => 'hello@acme.test']]],
        ['type' => 'contact_form', 'title' => 'Get in touch', 'fields' => [
            ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
            ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
            ['name' => 'when', 'type' => 'date', 'label' => 'When'],
        ]],
    ]);

    $html = underlineFormsNormalise(app(PageRenderer::class)->render($site, $page->id, mode: 'public'));
    $fixture = base_path('tests/Fixtures/ChromeSnapshots/contact-details-absorbed-form.html');

    expect($html)->toBe(file_get_contents($fixture));
});

it('null form_style lead form page matches the Task 0 fixture', function () {
    [$site, $page] = makeUnderlineFormsPage(
        'roofing',
        [],
        ['home_lead_form_enabled' => true],
        [
            ['type' => 'hero', 'title' => 'Roofing', 'accent_word' => 'Roofing'],
            ['type' => 'lead_form', 'title' => 'Quote', 'extra_fields' => [
                ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
                ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
                ['name' => 'when', 'type' => 'date', 'label' => 'When'],
            ]],
        ],
    );

    $html = underlineFormsNormalise(app(PageRenderer::class)->render($site, $page->id, mode: 'public'));
    $fixture = base_path('tests/Fixtures/ChromeSnapshots/lead-form-page.html');

    expect($html)->toBe(file_get_contents($fixture));
});
