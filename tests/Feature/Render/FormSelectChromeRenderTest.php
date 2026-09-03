<?php

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\FormChrome;
use App\Services\Site\PageRenderer;

/**
 * Boxed/null-knob <select> class literals captured from
 * `git show dev:resources/views/site/sections/{contact_form,details,lead_form}.blade.php`
 * (contact/details used `$inputClass`; lead's select had its own `bg-white` string).
 */
const DEV_CONTACT_SELECT_CLASS = 'w-full rounded-md border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:border-transparent transition-shadow';

const DEV_LEAD_SELECT_CLASS = 'w-full px-4 py-2.5 rounded-md border border-gray-300 text-gray-900 bg-white focus:outline-none focus:ring-2 focus:border-transparent transition-shadow';

/**
 * @param  array<string, mixed>  $siteAttrs
 * @param  array<string, mixed>  $profile
 * @param  list<array<string, mixed>>  $sections
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeSelectChromePage(string $pageType, array $siteAttrs, array $profile, array $sections): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Roofing', 'theme' => 'trades-bold'] + $siteAttrs);

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
            'footer' => ['columns' => [], 'show_credit' => true],
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

function firstSelectClass(string $html): string
{
    expect(preg_match('/<select\b[^>]*\bclass="([^"]*)"/', $html, $match))->toBe(1);

    return $match[1];
}

it('null-knob contact, details and lead selects match the git-show-dev class literals', function () {
    expect(DEV_CONTACT_SELECT_CLASS)->toBe(FormChrome::BOXED_SELECT)
        ->toBe(FormChrome::BOXED_INPUT)
        ->not->toContain('appearance-none')
        ->and(DEV_LEAD_SELECT_CLASS)->toBe(FormChrome::LEAD_BOXED_SELECT)
        ->toContain('bg-white')
        ->not->toContain('appearance-none')
        ->not->toContain('placeholder-gray-400');

    $fields = [
        ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
        ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
        ['name' => 'when', 'type' => 'date', 'label' => 'When'],
    ];

    [$contactSite, $contactPage] = makeSelectChromePage('contact', [], [], [
        ['type' => 'details', 'title' => 'Contact', 'items' => [['label' => 'Email', 'value' => 'hello@acme.test']]],
        ['type' => 'contact_form', 'title' => 'Get in touch', 'fields' => $fields],
    ]);
    $contactHtml = app(PageRenderer::class)->render($contactSite, $contactPage->id, mode: 'public');
    expect(firstSelectClass($contactHtml))->toBe(DEV_CONTACT_SELECT_CLASS);

    [$leadSite, $leadPage] = makeSelectChromePage(
        'roofing',
        [],
        ['home_lead_form_enabled' => true],
        [
            ['type' => 'hero', 'title' => 'Roofing', 'accent_word' => 'Roofing'],
            ['type' => 'lead_form', 'title' => 'Quote', 'extra_fields' => $fields],
        ],
    );
    $leadHtml = app(PageRenderer::class)->render($leadSite, $leadPage->id, mode: 'public');
    expect(firstSelectClass($leadHtml))->toBe(DEV_LEAD_SELECT_CLASS);
});
