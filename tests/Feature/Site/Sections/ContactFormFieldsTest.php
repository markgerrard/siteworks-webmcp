<?php

/*
 * The contact-form editor in page-manager has always saved $section['fields'],
 * and contact_form.blade.php has always ignored them -- it hardcoded
 * name/email/phone/message. Every edit a client made there was write-only.
 *
 * Wiring it up is constrained by SiteEnquirySubmitController, which REQUIRES
 * name and email and treats everything else as free-form payload. So a client
 * field list can shape the rest of the form but must never be able to remove
 * the two fields the endpoint validates on, or every submission 422s and the
 * lead is lost.
 */

function renderContactForm(array $section): string
{
    return view('site.sections.contact_form', [
        'section' => array_merge(['type' => 'contact_form'], $section),
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();
}

test('a form with no defined fields keeps its standard four', function () {
    // Every existing site is in this state. If this breaks, live contact
    // forms lose their fields.
    $html = renderContactForm([]);

    expect($html)->toContain('name="name"')
        ->and($html)->toContain('name="email"')
        ->and($html)->toContain('name="phone"')
        ->and($html)->toContain('name="message"');
});

test('client-defined fields are rendered', function () {
    $html = renderContactForm([
        'fields' => [
            ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text', 'required' => false],
        ],
    ]);

    expect($html)->toContain('name="job_postcode"')
        ->and($html)->toContain('Job postcode');
});

test('name and email survive a field list that omits them', function () {
    // The endpoint validates on these two. A client tidying their form must
    // not be able to break lead capture.
    $html = renderContactForm([
        'fields' => [
            ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text', 'required' => false],
        ],
    ]);

    expect($html)->toContain('name="name"')
        ->and($html)->toContain('name="email"');
});

test('name and email are not duplicated when the field list includes them', function () {
    // Two inputs sharing a name would submit twice and the last would win.
    $html = renderContactForm([
        'fields' => [
            ['name' => 'email', 'label' => 'Your email address', 'type' => 'text', 'required' => true],
        ],
    ]);

    expect(substr_count($html, 'name="email"'))->toBe(1);
});

test('a select field renders its options', function () {
    $html = renderContactForm([
        'fields' => [
            ['name' => 'service', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler', 'Drains'], 'required' => false],
        ],
    ]);

    expect($html)->toContain('<select')
        ->and($html)->toContain('Boiler')
        ->and($html)->toContain('Drains');
});

test('fields with no key are skipped', function () {
    // page-manager carries `name` through from whatever the generator wrote,
    // so a field can legitimately arrive with an empty key. Rendering it
    // would emit an input whose value goes nowhere.
    $html = renderContactForm([
        'fields' => [
            ['name' => '', 'label' => 'Orphan', 'type' => 'text'],
            ['name' => 'kept', 'label' => 'Kept', 'type' => 'text'],
        ],
    ]);

    expect($html)->toContain('name="kept"')
        ->and($html)->not->toContain('Orphan');
});

test('a field label cannot inject markup', function () {
    // Field labels are client-controlled and land on the public site.
    $html = renderContactForm([
        'fields' => [
            ['name' => 'x', 'label' => '<script>alert(1)</script>', 'type' => 'text'],
        ],
    ]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});
