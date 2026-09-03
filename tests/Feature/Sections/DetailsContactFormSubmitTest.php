<?php

/**
 * The `details` section renders its OWN contact form whenever a page pairs
 * `details` with `contact_form` — page.blade.php "absorbs" the standalone
 * section into the details two-column layout and skips it. That absorbed form
 * is therefore the one real visitors actually see on a contact page.
 *
 * It was left posting nowhere (`action="#"`, `@submit.prevent="submitted = true"`)
 * when lead_form and contact_form were wired to /enquiries, so submissions were
 * silently discarded while the visitor was shown a "Thank You" panel. These tests
 * pin the absorbed path so the two implementations cannot drift apart again.
 */
function renderDetailsWithAbsorbedForm(array $overrides = []): string
{
    return view('site.sections.details', array_merge([
        'section' => [
            'type' => 'details',
            'title' => 'Get In Touch',
            'items' => [
                ['label' => 'Email', 'value' => 'hello@example.com'],
                ['label' => 'Phone', 'value' => '0208 425 0251'],
            ],
        ],
        'contactFormSection' => [
            'type' => 'contact_form',
            'title' => 'Send us a message',
            'submit_label' => 'Send Message',
        ],
        'sectionIndex' => 1,
        'pageId' => 7,
        'pageType' => 'contact',
        'emitMarkers' => false,
        'profile' => [],
        'site' => null,
    ], $overrides))->render();
}

test('the absorbed contact form posts to /enquiries', function () {
    $html = renderDetailsWithAbsorbedForm();

    expect($html)->toContain("fetch('/enquiries'");
});

test('the absorbed contact form does not fake its submission', function () {
    $html = renderDetailsWithAbsorbedForm();

    // The original bug: the form swallowed the submit and flipped straight to
    // the thank-you state without sending anything anywhere.
    expect($html)->not->toContain('action="#"')
        ->and($html)->not->toContain('@submit.prevent="submitted = true"')
        ->and($html)->toContain('submit($event.target)');
});

test('the absorbed contact form carries the honeypot and page_type', function () {
    $html = renderDetailsWithAbsorbedForm();

    expect($html)->toContain('name="website"')
        ->and($html)->toContain('name="page_type"')
        ->and($html)->toContain('value="contact"');
});

test('the absorbed contact form keeps the fields the endpoint expects', function () {
    $html = renderDetailsWithAbsorbedForm();

    foreach (['name="name"', 'name="email"', 'name="phone"', 'name="message"'] as $field) {
        expect($html)->toContain($field);
    }
});

test('the absorbed contact form surfaces errors instead of silently succeeding', function () {
    $html = renderDetailsWithAbsorbedForm();

    expect($html)->toContain('x-text="error"')
        ->and($html)->toContain('x-bind:disabled="sending"');
});

test('no form renders when the page has no contact_form to absorb', function () {
    $html = renderDetailsWithAbsorbedForm(['contactFormSection' => null]);

    expect($html)->not->toContain('<form');
});
