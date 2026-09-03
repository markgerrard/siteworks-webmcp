<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\PageRenderer;

/*
 * The WYSIWYG panel opens from a click on a form that carries
 * data-form-editable. Those attributes must only emit in admin-edit
 * (emitMarkers), and on an absorbed contact form they must name the
 * contact_form section — not the details section that renders it.
 */

test('an editable form carries selection markers only when markers are on', function () {
    $html = view('site.sections.contact_form', [
        'section' => ['type' => 'contact_form', 'title' => 'Contact'],
        'sectionIndex' => 2,
        'pageId' => 42,
        'emitMarkers' => true,
        'emitFormMarkers' => true,
    ])->render();

    expect($html)->toContain('data-form-editable="page.42.section.2"')
        ->and($html)->toContain('data-form-kind="contact_form"');
});

test('a public render carries no selection markers', function () {
    // These attributes must never reach a visitor's page.
    $html = view('site.sections.contact_form', [
        'section' => ['type' => 'contact_form', 'title' => 'Contact'],
        'sectionIndex' => 2,
        'pageId' => 42,
        'emitMarkers' => false,
        'emitFormMarkers' => false,
    ])->render();

    expect($html)->not->toContain('data-form-editable');
});

test('an editable lead form carries its own selection markers', function () {
    $html = view('site.sections.lead_form', [
        'section' => ['type' => 'lead_form', 'title' => 'Get in touch'],
        'sectionIndex' => 4,
        'pageId' => 42,
        'emitMarkers' => true,
        'emitFormMarkers' => true,
    ])->render();

    expect($html)->toContain('data-form-editable="page.42.section.4"')
        ->and($html)->toContain('data-form-kind="lead_form"');
});

test('a public lead form carries no selection markers', function () {
    $html = view('site.sections.lead_form', [
        'section' => ['type' => 'lead_form', 'title' => 'Get in touch'],
        'sectionIndex' => 4,
        'pageId' => 42,
        'emitMarkers' => false,
        'emitFormMarkers' => false,
    ])->render();

    expect($html)->not->toContain('data-form-editable');
});

test('an absorbed form marks the contact_form section, not the details section', function () {
    // details.blade.php is what actually renders the form on a normal
    // contact page. If the marker used details' own $sectionIndex the
    // panel would silently POST against the details section.
    $html = view('site.sections.details', [
        'section' => [
            'type' => 'details',
            'title' => 'Get In Touch',
            'items' => [
                ['label' => 'Email', 'value' => 'hello@example.test'],
            ],
        ],
        'sectionIndex' => 1,
        'pageId' => 42,
        'emitMarkers' => true,
        'emitFormMarkers' => true,
        'pageType' => 'contact',
        'profile' => [],
        'contactFormSection' => [
            'type' => 'contact_form',
            'title' => 'Send Us a Message',
        ],
        'contactFormSectionIndex' => 3,
    ])->render();

    expect($html)->toContain('data-form-editable="page.42.section.3"')
        ->and($html)->toContain('data-form-kind="contact_form"')
        ->and($html)->not->toContain('data-form-editable="page.42.section.1"');
});

test('a public absorbed form carries no selection markers', function () {
    $html = view('site.sections.details', [
        'section' => [
            'type' => 'details',
            'title' => 'Get In Touch',
            'items' => [
                ['label' => 'Email', 'value' => 'hello@example.test'],
            ],
        ],
        'sectionIndex' => 1,
        'pageId' => 42,
        'emitMarkers' => false,
        'emitFormMarkers' => false,
        'pageType' => 'contact',
        'profile' => [],
        'contactFormSection' => [
            'type' => 'contact_form',
            'title' => 'Send Us a Message',
        ],
        'contactFormSectionIndex' => 3,
    ])->render();

    expect($html)->not->toContain('data-form-editable');
});

test('page render threads the absorbed contact_form index into the marker', function () {
    $this->withoutVite();

    $site = Site::factory()->create(['business_name' => 'Marker Co', 'theme' => 'trades-bold']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Hi'],
            ['type' => 'details', 'title' => 'Get In Touch', 'items' => [
                ['label' => 'Email', 'value' => 'hello@example.test'],
            ]],
            ['type' => 'contact_form', 'title' => 'Send Us a Message'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'admin-edit', formPanel: true);

    expect($html)->toContain('data-form-editable="page.'.$page->id.'.section.2"')
        ->and($html)->toContain('data-form-kind="contact_form"')
        ->and($html)->not->toContain('data-form-editable="page.'.$page->id.'.section.1"');
});
