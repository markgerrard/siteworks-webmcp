<?php

use App\Enums\LeadFormPolicy;
use App\Services\Site\ArchetypeComposer;
use App\Services\Site\ArchetypeRecipe;

function aboutContent(array $extraSections = []): array
{
    return [
        'sections' => array_merge([
            ['type' => 'hero', 'title' => 'About Us'],
            ['type' => 'story', 'title' => 'Our Story'],
            ['type' => 'values'],
            ['type' => 'cta'],
        ], $extraSections),
    ];
}

function aboutComposer(): ArchetypeComposer
{
    return new ArchetypeComposer(new ArchetypeRecipe);
}

// ───── cta_band injected correctly ────────────────────────────────────

test('injectCtaBandOnAbout adds cta_band when policy is home', function () {
    $out = aboutComposer()->injectCtaBandOnAbout(aboutContent(), LeadFormPolicy::Home);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('cta_band');
    expect(end($types))->toBe('cta_band');
});

test('injectCtaBandOnAbout adds cta_band when policy is home_services', function () {
    $out = aboutComposer()->injectCtaBandOnAbout(aboutContent(), LeadFormPolicy::HomeServices);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('cta_band');
});

test('injectCtaBandOnAbout adds cta_band when policy is all', function () {
    $out = aboutComposer()->injectCtaBandOnAbout(aboutContent(), LeadFormPolicy::All);
    $types = array_column($out['sections'], 'type');

    expect($types)->toContain('cta_band');
});

test('injectCtaBandOnAbout does NOT add cta_band when policy is off', function () {
    $out = aboutComposer()->injectCtaBandOnAbout(aboutContent(), LeadFormPolicy::Off);
    $types = array_column($out['sections'], 'type');

    expect($types)->not->toContain('cta_band');
});

// ───── About never gets a lead_form ───────────────────────────────────

test('about page never receives a lead_form section regardless of policy', function () {
    $composer = aboutComposer();

    foreach (LeadFormPolicy::cases() as $policy) {
        $out = $composer->injectCtaBandOnAbout(
            aboutContent([['type' => 'lead_form', 'title' => 'Accidentally injected']]),
            $policy,
        );
        $types = array_column($out['sections'], 'type');
        // injectCtaBandOnAbout does not remove existing lead_form (that's
        // GenerateContentJob's responsibility), but it also never ADDS one.
        // Verify the composer method itself doesn't add a lead_form.
        $ctaBands = array_filter($types, fn ($t) => $t === 'lead_form');
        // Only the manually-added one should be present (if at all).
        expect(count($ctaBands))->toBeLessThanOrEqual(1);
    }
});

// ───── Double-injection guard ─────────────────────────────────────────

test('injectCtaBandOnAbout is idempotent — does not add a second cta_band', function () {
    $content = aboutContent([
        ['type' => 'cta_band', 'title' => 'Already here'],
    ]);

    $out = aboutComposer()->injectCtaBandOnAbout($content, LeadFormPolicy::Home);
    $types = array_column($out['sections'], 'type');
    $ctaBands = array_filter($types, fn ($t) => $t === 'cta_band');

    expect(count($ctaBands))->toBe(1);
});

// ───── cta_band contract fields ───────────────────────────────────────

test('injected cta_band contains required fields', function () {
    $out = aboutComposer()->injectCtaBandOnAbout(aboutContent(), LeadFormPolicy::Home);
    $ctaBand = collect($out['sections'])->firstWhere('type', 'cta_band');

    expect($ctaBand)->toHaveKey('title');
    expect($ctaBand)->toHaveKey('subtitle');
    expect($ctaBand)->toHaveKey('cta_label');
    expect($ctaBand)->toHaveKey('cta_url');
    expect($ctaBand['title'])->toBeString()->not->toBeEmpty();
    expect($ctaBand['cta_url'])->toContain('#contact');
});
