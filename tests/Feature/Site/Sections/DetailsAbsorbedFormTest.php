<?php

/*
 * When a page carries both `details` and `contact_form`, page.blade.php makes
 * details ABSORB the form and skips the standalone section
 * (site/page.blade.php:266-295). That is the normal shape of a generated
 * contact page, so this template -- not contact_form.blade.php -- renders the
 * form most visitors actually see.
 *
 * It hardcoded name/email/phone/message and ignored the absorbed section's
 * `fields` entirely, which is why wiring contact_form.blade.php alone did not
 * change what clients saw.
 */

function renderDetailsWithForm(?array $contactForm, array $items = []): string
{
    return view('site.sections.details', [
        'section' => [
            'type' => 'details',
            'title' => 'Get In Touch',
            'items' => $items ?: [
                ['label' => 'Email', 'value' => 'hello@example.test'],
                ['label' => 'Phone', 'value' => '01234 567890'],
            ],
        ],
        'sectionIndex' => 1,
        'pageId' => 7,
        'emitMarkers' => false,
        'pageType' => 'contact',
        'profile' => [],
        'contactFormSection' => $contactForm,
    ])->render();
}

test('an absorbed form with no fields keeps the standard four', function () {
    // Every existing site is in this state.
    $html = renderDetailsWithForm(['type' => 'contact_form', 'title' => 'Send Us a Message']);

    expect($html)->toContain('name="name"')
        ->and($html)->toContain('name="email"')
        ->and($html)->toContain('name="phone"')
        ->and($html)->toContain('name="message"');
});

test('client-defined fields are rendered on the absorbed form', function () {
    $html = renderDetailsWithForm([
        'type' => 'contact_form',
        'fields' => [
            ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text', 'required' => false],
        ],
    ]);

    expect($html)->toContain('name="job_postcode"')
        ->and($html)->toContain('Job postcode');
});

test('name and email survive a field list that omits them', function () {
    // SiteEnquirySubmitController validates on both; without them every
    // submission 422s and the lead is lost.
    $html = renderDetailsWithForm([
        'type' => 'contact_form',
        'fields' => [
            ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text'],
        ],
    ]);

    expect($html)->toContain('name="name"')
        ->and($html)->toContain('name="email"');
});

test('name and email are not duplicated when the field list includes them', function () {
    $html = renderDetailsWithForm([
        'type' => 'contact_form',
        'fields' => [
            ['name' => 'email', 'label' => 'Your email', 'type' => 'text'],
        ],
    ]);

    expect(substr_count($html, 'name="email"'))->toBe(1);
});

test('a select field on the absorbed form renders its options', function () {
    $html = renderDetailsWithForm([
        'type' => 'contact_form',
        'fields' => [
            ['name' => 'service', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler', 'Drains']],
        ],
    ]);

    expect($html)->toContain('<select')
        ->and($html)->toContain('Boiler')
        ->and($html)->toContain('Drains');
});

test('a field label cannot inject markup into the absorbed form', function () {
    $html = renderDetailsWithForm([
        'type' => 'contact_form',
        'fields' => [
            ['name' => 'x', 'label' => '<script>alert(1)</script>', 'type' => 'text'],
        ],
    ]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});

test('the absorbed form keeps its honeypot and page_type wiring', function () {
    // The endpoint relies on both; losing them while restructuring the
    // fields would break spam handling and page attribution silently.
    $html = renderDetailsWithForm([
        'type' => 'contact_form',
        'fields' => [
            ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text'],
        ],
    ]);

    expect($html)->toContain('name="website"')
        ->and($html)->toContain('name="page_type"');
});
