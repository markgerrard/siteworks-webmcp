<?php

use App\Services\Site\ContentShapeTranslator;
use App\Services\Site\SectionSchema;

beforeEach(function () {
    $this->translator = new ContentShapeTranslator(
        new SectionSchema(config('site_sections', []))
    );
});

test('hero: renames heading→title, subheading→subtitle, keeps cta_label', function () {
    $out = $this->translator->translate([
        'hero' => [
            'heading' => 'Hello',
            'subheading' => 'World',
            'cta_label' => 'Click',
        ],
    ]);

    expect($out['sections'])->toHaveCount(1);
    expect($out['sections'][0])->toMatchArray([
        'type' => 'hero',
        'title' => 'Hello',
        'subtitle' => 'World',
        'cta_label' => 'Click',
    ]);
});

test('services: wraps rich intro and items.*.body as TipTap docs', function () {
    $out = $this->translator->translate([
        'services' => [
            'heading' => 'Our Services',
            'intro' => 'We offer quality work.',
            'items' => [
                ['title' => 'Boilers', 'body' => 'Install and repair.'],
                ['title' => 'Bathrooms', 'body' => 'Full refits.'],
            ],
        ],
    ]);

    $services = $out['sections'][0];
    expect($services['type'])->toBe('services');
    expect($services['title'])->toBe('Our Services');
    expect($services['intro'])->toMatchArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'We offer quality work.']],
        ]],
    ]);
    expect($services['items'][0]['title'])->toBe('Boilers');
    expect($services['items'][0]['body']['type'])->toBe('doc');
    expect($services['items'][0]['body']['content'][0]['content'][0]['text'])->toBe('Install and repair.');
    expect($services['items'][1]['body']['type'])->toBe('doc');
});

test('trust: keeps items flat with plain body (not rich)', function () {
    $out = $this->translator->translate([
        'trust' => [
            'heading' => 'Why Us',
            'items' => [
                ['title' => '20 Years', 'body' => 'Experienced team.'],
            ],
        ],
    ]);

    $trust = $out['sections'][0];
    expect($trust['type'])->toBe('trust');
    expect($trust['title'])->toBe('Why Us');
    // `trust.items.*.body` is plain — must NOT be wrapped as TipTap.
    expect($trust['items'][0]['body'])->toBe('Experienced team.');
});

test('story: wraps rich body, renames heading', function () {
    $out = $this->translator->translate([
        'story' => [
            'heading' => 'Our Story',
            'body' => 'Founded in 1995.',
        ],
    ]);

    $story = $out['sections'][0];
    expect($story['title'])->toBe('Our Story');
    expect($story['body']['type'])->toBe('doc');
});

test('values: items pass through with plain body', function () {
    $out = $this->translator->translate([
        'values' => [
            'heading' => 'Values',
            'items' => [['title' => 'Trust', 'body' => 'We deliver.']],
        ],
    ]);

    $v = $out['sections'][0];
    expect($v['title'])->toBe('Values');
    expect($v['items'][0]['body'])->toBe('We deliver.');
});

test('details: converts flat fields into items array with labels, skipping empties', function () {
    $out = $this->translator->translate([
        'details' => [
            'heading' => 'Contact',
            'email' => 'a@b.com',
            'phone' => '01234 567890',
            'address' => '1 High St',
            'coverage' => '',
        ],
    ]);

    $d = $out['sections'][0];
    expect($d['type'])->toBe('details');
    expect($d['title'])->toBe('Contact');
    expect($d['items'])->toEqual([
        ['label' => 'Email', 'value' => 'a@b.com'],
        ['label' => 'Phone', 'value' => '01234 567890'],
        ['label' => 'Address', 'value' => '1 High St'],
    ]);
});

test('contact_form: keeps heading+submit_label, drops fields and privacy_note', function () {
    $out = $this->translator->translate([
        'contact_form' => [
            'heading' => 'Get in touch',
            'submit_label' => 'Send',
            'privacy_note' => 'We never share your data.',
            'fields' => ['name', 'email'],
        ],
    ]);

    $cf = $out['sections'][0];
    expect($cf)->toEqual([
        'type' => 'contact_form',
        'title' => 'Get in touch',
        'submit_label' => 'Send',
    ]);
});

test('intro: drops eyebrow, wraps body as rich', function () {
    $out = $this->translator->translate([
        'intro' => [
            'eyebrow' => 'Welcome',
            'heading' => 'About',
            'body' => 'We are local.',
        ],
    ]);

    $intro = $out['sections'][0];
    expect($intro)->not->toHaveKey('eyebrow');
    expect($intro['title'])->toBe('About');
    expect($intro['body']['type'])->toBe('doc');
});

test('process: wraps items.*.body as rich', function () {
    $out = $this->translator->translate([
        'process' => [
            'heading' => 'How We Work',
            'items' => [
                ['step' => '1', 'title' => 'Survey', 'body' => 'We visit site.'],
            ],
        ],
    ]);

    $p = $out['sections'][0];
    expect($p['title'])->toBe('How We Work');
    expect($p['items'][0]['step'])->toBe('1');
    expect($p['items'][0]['body']['type'])->toBe('doc');
});

test('faqs: wraps items.*.answer as rich', function () {
    $out = $this->translator->translate([
        'faqs' => [
            'heading' => 'FAQs',
            'items' => [
                ['question' => 'Are you insured?', 'answer' => 'Yes, fully.'],
            ],
        ],
    ]);

    $f = $out['sections'][0];
    expect($f['items'][0]['question'])->toBe('Are you insured?');
    expect($f['items'][0]['answer']['type'])->toBe('doc');
});

test('benefits: items.*.body stays plain', function () {
    $out = $this->translator->translate([
        'benefits' => [
            'heading' => 'Why Choose',
            'items' => [['title' => 'Fast', 'body' => 'Same-week install']],
        ],
    ]);

    $b = $out['sections'][0];
    expect($b['items'][0]['body'])->toBe('Same-week install');
});

test('cta: heading→title, subheading→body, cta_label→button_label; cta_url dropped', function () {
    $out = $this->translator->translate([
        'cta' => [
            'heading' => 'Ready?',
            'subheading' => 'Call today.',
            'cta_label' => 'Call Now',
            'cta_url' => '/contact',
        ],
    ]);

    $cta = $out['sections'][0];
    expect($cta)->toEqual([
        'type' => 'cta',
        'title' => 'Ready?',
        'body' => 'Call today.',
        'button_label' => 'Call Now',
    ]);
});

test('cta: accent_word passes through the special-case translator', function () {
    $out = $this->translator->translate([
        'cta' => [
            'heading' => 'Book Your Boiler Service',
            'subheading' => 'Same-week slots available.',
            'cta_label' => 'Book Now',
            'accent_word' => 'Boiler',
        ],
    ]);

    $cta = $out['sections'][0];
    expect($cta['accent_word'])->toBe('Boiler')
        ->and($cta['title'])->toBe('Book Your Boiler Service');
});

test('seo and geo live under meta, not sections', function () {
    $out = $this->translator->translate([
        'hero' => ['heading' => 'Hi'],
        'seo' => ['meta_title' => 'T', 'meta_description' => 'D'],
        'geo' => ['service_area' => 'Birmingham', 'nearby_areas' => ['Solihull']],
    ]);

    expect($out['sections'])->toHaveCount(1);
    expect($out['sections'][0]['type'])->toBe('hero');
    expect($out['meta']['seo'])->toEqual(['meta_title' => 'T', 'meta_description' => 'D']);
    expect($out['meta']['geo']['service_area'])->toBe('Birmingham');
});

test('sections are emitted in legacy orderSections order', function () {
    $out = $this->translator->translate([
        // Intentionally scrambled input order
        'cta' => ['heading' => 'C'],
        'hero' => ['heading' => 'H'],
        'faqs' => ['heading' => 'F'],
        'services' => ['heading' => 'S'],
    ]);

    $types = array_column($out['sections'], 'type');
    expect($types)->toEqual(['hero', 'services', 'faqs', 'cta']);
});

test('unknown section keys are dropped with a warning', function () {
    \Illuminate\Support\Facades\Log::spy();

    $out = $this->translator->translate([
        'hero' => ['heading' => 'Hi'],
        'random_unknown' => ['foo' => 'bar'],
    ]);

    $types = array_column($out['sections'], 'type');
    expect($types)->toEqual(['hero']);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
});

test('idempotent: translating twice returns input unchanged if already in new shape', function () {
    $first = $this->translator->translate([
        'hero' => ['heading' => 'Hello', 'subheading' => 'World'],
    ]);
    $second = $this->translator->translate($first);

    expect($second)->toEqual($first);
});

test('stringToTipTapDoc produces the documented shape', function () {
    $doc = $this->translator->stringToTipTapDoc('Hello');
    expect($doc)->toEqual([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Hello']],
        ]],
    ]);
});

test('stringToTipTapDoc splits on blank lines into multiple paragraphs', function () {
    $doc = $this->translator->stringToTipTapDoc("First paragraph.\n\nSecond paragraph.\n\nThird paragraph.");
    expect($doc['content'])->toHaveCount(3);
    expect($doc['content'][0]['content'][0]['text'])->toBe('First paragraph.');
    expect($doc['content'][1]['content'][0]['text'])->toBe('Second paragraph.');
    expect($doc['content'][2]['content'][0]['text'])->toBe('Third paragraph.');
});

test('stringToTipTapDoc preserves soft line breaks inside a paragraph', function () {
    $doc = $this->translator->stringToTipTapDoc("Line one.\nLine two.");
    expect($doc['content'])->toHaveCount(1);
    $inline = $doc['content'][0]['content'];
    expect($inline[0])->toEqual(['type' => 'text', 'text' => 'Line one.']);
    expect($inline[1])->toEqual(['type' => 'hardBreak']);
    expect($inline[2])->toEqual(['type' => 'text', 'text' => 'Line two.']);
});

test('stringToTipTapDoc normalises CRLF and multiple blank lines', function () {
    $doc = $this->translator->stringToTipTapDoc("A\r\n\r\n\r\nB");
    expect($doc['content'])->toHaveCount(2);
    expect($doc['content'][0]['content'][0]['text'])->toBe('A');
    expect($doc['content'][1]['content'][0]['text'])->toBe('B');
});

test('rich field already in array form is not re-wrapped', function () {
    $existingDoc = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pre-built']]]],
    ];

    $out = $this->translator->translate([
        'story' => ['heading' => 'S', 'body' => $existingDoc],
    ]);

    expect($out['sections'][0]['body'])->toEqual($existingDoc);
});

test('service-page lead_form round-trip: all fields preserved, placed in sections', function () {
    $extraFields = [
        ['name' => 'equipment_type', 'label' => 'Equipment type', 'type' => 'select', 'options' => ['Excavator', 'Skid steer', 'Compactor', 'Other'], 'required' => true],
        ['name' => 'duration', 'label' => 'Hire duration', 'type' => 'select', 'options' => ['Day', 'Week', 'Month', 'Longer'], 'required' => true],
        ['name' => 'delivery_required', 'label' => 'Delivery required?', 'type' => 'radio', 'options' => ['Yes', 'Self-collect'], 'required' => true],
        ['name' => 'start_date', 'label' => 'Start date', 'type' => 'text', 'required' => false],
    ];

    $input = [
        'hero' => ['heading' => 'Plant Hire Perth', 'subheading' => 'Sub'],
        'cta' => ['heading' => 'Need Plant Hire?', 'cta_label' => 'Get a quote'],
        'lead_form' => [
            'title' => 'Get a Plant & Equipment Hire quote',
            'intro' => 'Tell us about your hire needs and we\'ll respond within one business day.',
            'benefits' => ['Fully insured for plant operation', 'Free site visit included', 'CHAS-accredited contractor'],
            'submit_label' => 'Get my quote',
            'extra_fields' => $extraFields,
        ],
    ];

    $out = $this->translator->translate($input);

    $types = array_column($out['sections'], 'type');
    expect($types)->toContain('lead_form');

    $lf = collect($out['sections'])->firstWhere('type', 'lead_form');
    expect($lf)->not->toBeNull();
    expect($lf['title'])->toBe('Get a Plant & Equipment Hire quote');
    expect($lf['intro'])->toBe('Tell us about your hire needs and we\'ll respond within one business day.');
    expect($lf['benefits'])->toEqual(['Fully insured for plant operation', 'Free site visit included', 'CHAS-accredited contractor']);
    expect($lf['submit_label'])->toBe('Get my quote');
    expect($lf['extra_fields'])->toEqual($extraFields);
});

test('service-page lead_form: heading alias maps to title', function () {
    $out = $this->translator->translate([
        'lead_form' => [
            'heading' => 'Get a quote',
            'intro' => 'Intro text.',
            'benefits' => ['Benefit A', 'Benefit B', 'Benefit C'],
            'submit_label' => 'Send',
            'extra_fields' => [],
        ],
    ]);

    $lf = collect($out['sections'])->firstWhere('type', 'lead_form');
    expect($lf['title'])->toBe('Get a quote');
});

test('service-page lead_form: extra_fields filters out non-array entries', function () {
    $out = $this->translator->translate([
        'lead_form' => [
            'title' => 'Get a quote',
            'intro' => 'Intro.',
            'benefits' => ['A', 'B', 'C'],
            'submit_label' => 'Send',
            'extra_fields' => [
                ['name' => 'valid_field', 'label' => 'Valid', 'type' => 'text', 'required' => true],
                'not-an-array',
                42,
            ],
        ],
    ]);

    $lf = collect($out['sections'])->firstWhere('type', 'lead_form');
    expect($lf['extra_fields'])->toHaveCount(1);
    expect($lf['extra_fields'][0]['name'])->toBe('valid_field');
});

test('service_display_name is preserved under meta after translation', function () {
    $out = $this->translator->translate([
        'hero' => ['heading' => 'Plant Hire Perth'],
        'service_display_name' => 'Plant & Equipment Hire',
        'seo' => ['meta_title' => 'Plant Hire', 'meta_description' => 'Desc'],
    ]);

    expect($out['meta']['service_display_name'])->toBe('Plant & Equipment Hire');
    // seo still present
    expect($out['meta']['seo']['meta_title'])->toBe('Plant Hire');
    // service_display_name must NOT appear as a section
    $types = array_column($out['sections'], 'type');
    expect($types)->not->toContain('service_display_name');
});
