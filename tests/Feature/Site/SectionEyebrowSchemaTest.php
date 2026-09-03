<?php

use App\Services\Site\SectionSchema;

dataset('eyebrow_section_types', [
    'hero', 'benefits', 'process', 'services', 'values', 'features',
    'about-text', 'story', 'intro', 'faqs', 'details', 'contact_form',
    'portfolio_strip', 'who_we_help_strip', 'case_study_teaser',
    'opening_hours_strip', 'lead_form', 'suburb_list', 'project_gallery',
]);

test('eyebrow is a known plain field on every eyebrow-bearing section type', function (string $sectionType) {
    $schema = app(SectionSchema::class);
    $rules = $schema->resolveField($sectionType, 'eyebrow');

    expect($rules)->not->toBeNull("expected '{$sectionType}.eyebrow' to be schema-defined")
        ->and($rules['type'])->toBe('plain');
})->with('eyebrow_section_types');

test('eyebrow accepts a 60-char string and rejects beyond', function () {
    $schema = app(SectionSchema::class);
    expect($schema->validateField('hero', 'eyebrow', str_repeat('a', 60)))->toBe([]);
    expect($schema->validateField('hero', 'eyebrow', str_repeat('a', 61)))->not->toBe([]);
});
