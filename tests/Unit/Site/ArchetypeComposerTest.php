<?php

use App\Enums\Archetype;
use App\Enums\LeadFormPolicy;
use App\Services\Site\ArchetypeComposer;
use App\Services\Site\ArchetypeRecipe;

beforeEach(fn () => $this->composer = new ArchetypeComposer(new ArchetypeRecipe));

function sectionsArray(array $sections, array $meta = []): array
{
    $out = ['sections' => $sections];
    if ($meta !== []) {
        $out['meta'] = $meta;
    }

    return $out;
}

test('emergency_trade recipe reorders sections with phone_cta_strip inserted after hero', function () {
    $content = sectionsArray([
        ['type' => 'hero', 'title' => 'Emergency Plumber'],
        ['type' => 'services', 'title' => 'What we do'],
        ['type' => 'trust', 'title' => 'Why us'],
        ['type' => 'cta', 'title' => 'Call now'],
    ]);

    $out = $this->composer->compose($content, Archetype::EmergencyTrade);
    $types = array_column($out['sections'], 'type');

    // emergency_trade now includes process (added phase-B) before suburb_list
    expect($types)->toBe(['hero', 'phone_cta_strip', 'services', 'trust', 'process', 'suburb_list', 'cta']);
});

test('compose populates phone_cta_strip with archetype copy', function () {
    // Without this populate, the recipe-driven phone_cta_strip on the
    // EmergencyTrade home would render the blade's neutral fallback
    // instead of "24/7 Emergency Call-Out" — same root cause as the
    // injectServiceLeadForm fix on service pages.
    $content = sectionsArray([
        ['type' => 'hero', 'title' => 'Emergency Plumber'],
    ]);

    $out = $this->composer->compose($content, Archetype::EmergencyTrade);
    $strip = collect($out['sections'])->firstWhere('type', 'phone_cta_strip');

    expect($strip['title'])->toBe('24/7 Emergency Call-Out');
    expect($strip['subtitle'])->toBe('Rapid response across our coverage area');
});

test('compose preserves AI-generated section payloads when they match the recipe', function () {
    $content = sectionsArray([
        ['type' => 'hero', 'title' => 'Emergency Plumber', 'accent_word' => 'Plumber'],
        ['type' => 'services', 'title' => 'Our Services', 'items' => [['title' => 'Leak', 'body' => 'fast']]],
    ]);

    $out = $this->composer->compose($content, Archetype::EmergencyTrade);
    $byType = collect($out['sections'])->keyBy('type')->all();

    expect($byType['hero']['title'])->toBe('Emergency Plumber');
    expect($byType['hero']['accent_word'])->toBe('Plumber');
    expect($byType['services']['items'][0]['title'])->toBe('Leak');
});

test('compose inserts empty placeholders for recipe sections the pipeline did not generate', function () {
    $content = sectionsArray([
        ['type' => 'hero', 'title' => 'Gardens'],
        ['type' => 'services', 'title' => 'Our services'],
    ]);

    $out = $this->composer->compose($content, Archetype::LocalService);
    $types = array_column($out['sections'], 'type');

    // local_service now includes service_area_card (after services) and
    // process (after trust) — updated in phase-B/C.
    expect($types)->toBe(['hero', 'services', 'service_area_card', 'trust', 'process', 'lead_form', 'cta']);

    $leadForm = collect($out['sections'])->firstWhere('type', 'lead_form');
    expect($leadForm)->toBe(['type' => 'lead_form']);
});

test('compose merges recipe weights into matching sections without clobbering AI output', function () {
    $content = sectionsArray([
        ['type' => 'hero', 'title' => 'Book now'],
        ['type' => 'cta', 'title' => 'Get in touch'],
    ]);

    $out = $this->composer->compose($content, Archetype::EmergencyTrade);
    $byType = collect($out['sections'])->keyBy('type')->all();

    // Weight keys land on the section...
    expect($byType['hero']['emergency_variant'] ?? null)->toBeTrue();
    expect($byType['cta']['cta_type'] ?? null)->toBe('phone');
    // ...but existing AI-generated fields win on overlap.
    expect($byType['hero']['title'])->toBe('Book now');
});

test('compose preserves meta field untouched', function () {
    $content = sectionsArray(
        sections: [['type' => 'hero']],
        meta: ['seo' => ['title' => 'My Page']],
    );

    $out = $this->composer->compose($content, Archetype::LocalService);

    expect($out['meta'])->toBe(['seo' => ['title' => 'My Page']]);
});

test('compose handles missing or empty sections input gracefully', function () {
    $out = $this->composer->compose([], Archetype::LocalService);

    $types = array_column($out['sections'], 'type');
    expect($types)->toBe(['hero', 'services', 'service_area_card', 'trust', 'process', 'lead_form', 'cta']);
    foreach ($out['sections'] as $section) {
        // Every section is an empty placeholder because nothing matched.
        expect(array_keys($section))->toBe(['type']);
    }
});

test('lead_form is injected before cta when policy wants it but recipe omits it', function () {
    $content = sectionsArray([['type' => 'hero']]);

    // emergency_trade's recipe has no lead_form; HomeServices policy should
    // force one in just before cta.
    $out = $this->composer->compose($content, Archetype::EmergencyTrade, LeadFormPolicy::HomeServices);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('lead_form');
    $leadIdx = array_search('lead_form', $types, true);
    $ctaIdx = array_search('cta', $types, true);
    expect($leadIdx)->toBeLessThan($ctaIdx);
});

test('lead_form is not injected when policy is off', function () {
    $content = sectionsArray([['type' => 'hero']]);

    $out = $this->composer->compose($content, Archetype::EmergencyTrade, LeadFormPolicy::Off);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('lead_form');
});

test('lead_form is not duplicated when recipe already lists it', function () {
    $content = sectionsArray([['type' => 'hero']]);

    // local_service already has lead_form in its recipe — injection must
    // not add a second one.
    $out = $this->composer->compose($content, Archetype::LocalService, LeadFormPolicy::HomeServices);
    $types = array_column($out['sections'], 'type');

    $occurrences = count(array_filter($types, fn ($t) => $t === 'lead_form'));
    expect($occurrences)->toBe(1);
});

test('each archetype produces a distinctive section ordering', function () {
    $content = sectionsArray([['type' => 'hero']]);
    $orderings = [];
    foreach (Archetype::cases() as $case) {
        $out = $this->composer->compose($content, $case);
        $orderings[$case->value] = array_column($out['sections'], 'type');
    }

    expect($orderings['emergency_trade'])->not->toBe($orderings['premium_specialist']);
    expect($orderings['retail_venue'])->not->toBe($orderings['professional_service']);
    expect($orderings['traditional_craftsman'])->not->toBe($orderings['local_service']);
});
