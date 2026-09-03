<?php

use App\Enums\Archetype;
use App\Services\Site\ArchetypeComposer;
use App\Services\Site\ArchetypeRecipe;

beforeEach(fn () => $this->composer = new ArchetypeComposer(new ArchetypeRecipe));

function saasContentData(array $extra = []): array
{
    return [
        'sections' => array_merge([
            ['type' => 'hero', 'title' => 'The smarter way to manage projects'],
            ['type' => 'features', 'title' => 'Everything you need'],
            ['type' => 'services', 'title' => 'Plans'],
            ['type' => 'process', 'title' => 'How it works'],
            ['type' => 'trust', 'title' => 'Trusted by 2,000+ teams'],
            ['type' => 'cta', 'title' => 'Start for free'],
        ], $extra),
    ];
}

// ───── Trade-only sections must not appear ────────────────────────────────

test('SaasPlatform compose does not inject service_area_card', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('service_area_card');
});

test('SaasPlatform compose does not inject phone_cta_strip', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('phone_cta_strip');
});

test('SaasPlatform compose does not inject suburb_list or geo sections', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('suburb_list');
    expect($types)->not->toContain('geo');
});

test('SaasPlatform compose does not inject projects_hero or project_gallery', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('projects_hero');
    expect($types)->not->toContain('project_gallery');
});

test('SaasPlatform compose does not inject opening_hours_strip', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('opening_hours_strip');
});

// ───── Generic sections must appear ───────────────────────────────────────

test('SaasPlatform compose includes hero as the first section', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types[0])->toBe('hero');
});

test('SaasPlatform compose includes features section', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('features');
});

test('SaasPlatform compose includes services section', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('services');
});

test('SaasPlatform compose includes process section', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('process');
});

test('SaasPlatform compose includes cta as the final section', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect(end($types))->toBe('cta');
});

test('SaasPlatform compose includes lead_form from the recipe', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('lead_form');
});

// ───── AI-generated content is preserved ─────────────────────────────────

test('SaasPlatform compose preserves AI-generated section payloads', function () {
    $out = $this->composer->compose(saasContentData(), Archetype::SaasPlatform);
    $byType = collect($out['sections'])->keyBy('type')->all();

    expect($byType['hero']['title'])->toBe('The smarter way to manage projects');
    expect($byType['features']['title'])->toBe('Everything you need');
});

// ───── Ordering is distinct from other archetypes ────────────────────────

test('SaasPlatform section ordering is distinct from LocalService', function () {
    $content = ['sections' => [['type' => 'hero']]];
    $saasOut = $this->composer->compose($content, Archetype::SaasPlatform);
    $localOut = $this->composer->compose($content, Archetype::LocalService);

    $saasTypes = array_column($saasOut['sections'], 'type');
    $localTypes = array_column($localOut['sections'], 'type');

    expect($saasTypes)->not->toBe($localTypes);
});

test('SaasPlatform section ordering is distinct from ProfessionalService', function () {
    $content = ['sections' => [['type' => 'hero']]];
    $saasOut = $this->composer->compose($content, Archetype::SaasPlatform);
    $profOut = $this->composer->compose($content, Archetype::ProfessionalService);

    $saasTypes = array_column($saasOut['sections'], 'type');
    $profTypes = array_column($profOut['sections'], 'type');

    expect($saasTypes)->not->toBe($profTypes);
});
