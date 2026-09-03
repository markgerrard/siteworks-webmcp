<?php

use App\Services\Site\SectionSchema;

it('registers the three project section types', function () {
    $schema = app(SectionSchema::class);

    expect($schema->isKnownSectionType('projects_hero'))->toBeTrue();
    expect($schema->isKnownSectionType('project_gallery'))->toBeTrue();
    expect($schema->isKnownSectionType('case_study_highlights'))->toBeTrue();
});

it('constrains project sections to projects page type', function () {
    $schema = app(SectionSchema::class);

    expect($schema->for('projects_hero')['page_types'])->toBe(['projects']);
    expect($schema->for('project_gallery')['page_types'])->toBe(['projects']);
    expect($schema->for('case_study_highlights')['page_types'])->toBe(['projects']);
});

it('points each section to a blade partial under preview.sections', function () {
    $schema = app(SectionSchema::class);

    expect($schema->for('projects_hero')['render'])->toBe('site.sections.projects_hero');
    expect($schema->for('project_gallery')['render'])->toBe('site.sections.project_gallery');
    expect($schema->for('case_study_highlights')['render'])->toBe('site.sections.case_study_highlights');
});

it('accepts project_gallery.eyebrow as a plain field', function () {
    $schema = app(SectionSchema::class);

    expect($schema->resolveField('project_gallery', 'eyebrow'))
        ->toBe(['type' => 'plain', 'max' => 60]);
    expect($schema->validateField('project_gallery', 'eyebrow', 'Examples'))->toBe([]);
    expect($schema->validateField('project_gallery', 'eyebrow', str_repeat('a', 61)))->not->toBe([]);
});
