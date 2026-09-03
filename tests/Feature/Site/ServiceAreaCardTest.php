<?php

use App\Enums\Archetype;
use App\Services\Site\ArchetypeRecipe;
use App\Services\Site\ContentShapeTranslator;

// ───── Archetype recipe includes service_area_card for local trades ────

test('local_service recipe includes service_area_card', function () {
    $recipe = (new ArchetypeRecipe)->for(Archetype::LocalService);

    expect($recipe['sections'])->toContain('service_area_card');
});

test('service_area_card appears after services and before trust in local_service recipe', function () {
    $sections = (new ArchetypeRecipe)->for(Archetype::LocalService)['sections'];

    $servicesIdx = array_search('services', $sections);
    $cardIdx = array_search('service_area_card', $sections);
    $trustIdx = array_search('trust', $sections);

    expect($cardIdx)->toBeGreaterThan($servicesIdx);
    expect($cardIdx)->toBeLessThan($trustIdx);
});

test('emergency_trade recipe does NOT include service_area_card (suburb_list handles geo)', function () {
    $sections = (new ArchetypeRecipe)->for(Archetype::EmergencyTrade)['sections'];

    expect($sections)->not->toContain('service_area_card');
});

test('professional_service recipe does NOT include service_area_card', function () {
    $sections = (new ArchetypeRecipe)->for(Archetype::ProfessionalService)['sections'];

    expect($sections)->not->toContain('service_area_card');
});

// ───── ContentShapeTranslator preserves service_area_card ─────────────

test('translator preserves service_area_card in new-shape input (idempotent)', function () {
    $translator = app(ContentShapeTranslator::class);
    $section = [
        'type' => 'service_area_card',
        'title' => 'Servicing the West Midlands',
        'intro' => 'Reliable local service across Birmingham and beyond.',
        'areas' => ['Birmingham', 'Solihull', 'Coventry', 'Wolverhampton', 'Dudley'],
        'cta_label' => 'Check if we cover you',
        'cta_url' => '#contact',
    ];

    $out = $translator->translate(['sections' => [$section]]);

    expect($out['sections'][0])->toBe($section);
});

test('translator passes service_area_card through from legacy map shape', function () {
    $translator = app(ContentShapeTranslator::class);
    $legacy = [
        'hero' => ['heading' => 'Test'],
        'service_area_card' => [
            'title' => 'Servicing Greater Manchester',
            'intro' => 'Fast response across the North West.',
            'areas' => ['Manchester', 'Salford', 'Bury', 'Oldham'],
            'cta_label' => 'Check coverage',
            'cta_url' => '#contact',
        ],
    ];

    $out = $translator->translate($legacy);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('service_area_card');
    $card = collect($out['sections'])->firstWhere('type', 'service_area_card');
    expect($card['title'])->toBe('Servicing Greater Manchester');
    expect($card['areas'])->toBe(['Manchester', 'Salford', 'Bury', 'Oldham']);
    expect($card['cta_url'])->toBe('#contact');
});

test('translator filters non-string values out of service_area_card areas array', function () {
    $translator = app(ContentShapeTranslator::class);
    $legacy = [
        'service_area_card' => [
            'title' => 'Test',
            'intro' => 'Test intro.',
            'areas' => ['Birmingham', 42, null, 'Coventry'],
            'cta_label' => 'Check',
            'cta_url' => '#contact',
        ],
    ];

    $out = $translator->translate($legacy);
    $card = collect($out['sections'])->firstWhere('type', 'service_area_card');

    expect($card['areas'])->toBe(['Birmingham', 'Coventry']);
});

// ───── config/site_sections registration ──────────────────────────────

test('service_area_card is registered in site_sections config with correct fields', function () {
    $sections = config('site_sections');

    expect($sections)->toHaveKey('service_area_card');
    expect($sections['service_area_card']['fields'])->toHaveKey('title');
    expect($sections['service_area_card']['fields'])->toHaveKey('intro');
    expect($sections['service_area_card']['fields'])->toHaveKey('areas');
    expect($sections['service_area_card']['fields'])->toHaveKey('cta_label');
    expect($sections['service_area_card']['fields'])->toHaveKey('cta_url');
});

test('cta_band is registered in site_sections config with correct fields', function () {
    $sections = config('site_sections');

    expect($sections)->toHaveKey('cta_band');
    expect($sections['cta_band']['fields'])->toHaveKey('title');
    expect($sections['cta_band']['fields'])->toHaveKey('subtitle');
    expect($sections['cta_band']['fields'])->toHaveKey('cta_label');
    expect($sections['cta_band']['fields'])->toHaveKey('cta_url');
});
