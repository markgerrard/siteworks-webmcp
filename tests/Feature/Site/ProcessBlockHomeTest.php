<?php

use App\Enums\Archetype;
use App\Services\Site\ArchetypeComposer;
use App\Services\Site\ArchetypeRecipe;
use App\Services\Site\ContentShapeTranslator;

// ───── Archetype recipe includes process for trade archetypes ─────────

test('local_service recipe includes process section', function () {
    $recipe = new ArchetypeRecipe;
    $sections = $recipe->for(Archetype::LocalService)['sections'];

    expect($sections)->toContain('process');
});

test('emergency_trade recipe includes process section', function () {
    $recipe = new ArchetypeRecipe;
    $sections = $recipe->for(Archetype::EmergencyTrade)['sections'];

    expect($sections)->toContain('process');
});

test('premium_specialist recipe includes process section', function () {
    $recipe = new ArchetypeRecipe;
    $sections = $recipe->for(Archetype::PremiumSpecialist)['sections'];

    expect($sections)->toContain('process');
});

test('traditional_craftsman recipe does NOT include process section', function () {
    $recipe = new ArchetypeRecipe;
    $sections = $recipe->for(Archetype::TraditionalCraftsman)['sections'];

    expect($sections)->not->toContain('process');
});

test('professional_service recipe does NOT include process section', function () {
    $recipe = new ArchetypeRecipe;
    $sections = $recipe->for(Archetype::ProfessionalService)['sections'];

    expect($sections)->not->toContain('process');
});

test('retail_venue recipe does NOT include process section', function () {
    $recipe = new ArchetypeRecipe;
    $sections = $recipe->for(Archetype::RetailVenue)['sections'];

    expect($sections)->not->toContain('process');
});

// ───── ArchetypeComposer places process correctly ─────────────────────

test('composer places process after trust and before lead_form in local_service', function () {
    $content = [
        'sections' => [
            ['type' => 'hero'],
            ['type' => 'services'],
            ['type' => 'trust'],
            ['type' => 'process', 'heading' => 'How It Works', 'items' => [
                ['step' => 1, 'title' => 'Book a survey', 'body' => 'We visit at a time that suits you'],
                ['step' => 2, 'title' => 'Receive your quote', 'body' => 'We send a detailed quote within 24h'],
                ['step' => 3, 'title' => 'Work is scheduled', 'body' => 'We confirm dates that work for you'],
                ['step' => 4, 'title' => 'Aftercare included', 'body' => 'We follow up after every job'],
            ]],
            ['type' => 'lead_form'],
            ['type' => 'cta'],
        ],
    ];

    $out = (new ArchetypeComposer(new ArchetypeRecipe))->compose($content, Archetype::LocalService);
    $types = array_column($out['sections'], 'type');

    $processIdx = array_search('process', $types);
    $trustIdx = array_search('trust', $types);
    $leadFormIdx = array_search('lead_form', $types);

    expect($processIdx)->toBeGreaterThan($trustIdx);
    expect($processIdx)->toBeLessThan($leadFormIdx);
});

test('composer preserves AI-generated process items for local_service', function () {
    $processItems = [
        ['step' => 1, 'icon' => 'phone', 'title' => 'Call us', 'body' => 'Get in touch'],
        ['step' => 2, 'icon' => 'calendar', 'title' => 'Book a date', 'body' => 'We confirm'],
        ['step' => 3, 'icon' => 'hammer', 'title' => 'Work done', 'body' => 'Quality finish'],
        ['step' => 4, 'icon' => 'award', 'title' => 'Aftercare', 'body' => 'We follow up'],
    ];
    $content = [
        'sections' => [
            ['type' => 'hero'],
            ['type' => 'process', 'items' => $processItems],
        ],
    ];

    $out = (new ArchetypeComposer(new ArchetypeRecipe))->compose($content, Archetype::LocalService);
    $process = collect($out['sections'])->firstWhere('type', 'process');

    expect($process['items'])->toBe($processItems);
});

// ───── ContentShapeTranslator round-trips process ─────────────────────

test('translator round-trips process through new-shape content unchanged', function () {
    $translator = app(ContentShapeTranslator::class);
    $processSection = [
        'type' => 'process',
        'heading' => 'How It Works',
        'items' => [
            ['step' => 1, 'icon' => 'phone', 'title' => 'Call us', 'body' => 'We listen'],
        ],
    ];
    $input = ['sections' => [$processSection]];

    // Already new-shape — idempotent
    $out = $translator->translate($input);

    expect($out)->toBe($input);
});

test('translator includes process in SECTION_ORDER so legacy flat shape is picked up', function () {
    $translator = app(ContentShapeTranslator::class);
    $legacy = [
        'hero' => ['heading' => 'Hero Title'],
        'process' => [
            'heading' => 'How It Works',
            'items' => [['step' => 1, 'title' => 'Step one', 'body' => 'Do it']],
        ],
    ];

    $out = $translator->translate($legacy);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('process');
    $proc = collect($out['sections'])->firstWhere('type', 'process');
    expect($proc['items'][0]['title'])->toBe('Step one');
});

// ───── config/site_sections registration ──────────────────────────────

test('process section type is registered in site_sections config', function () {
    expect(config('site_sections'))->toHaveKey('process');
});
