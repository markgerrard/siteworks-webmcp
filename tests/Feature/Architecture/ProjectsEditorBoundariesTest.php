<?php

// Verify projects-page editor components don't leak into page-level concerns.
// Small components stay small; boundaries don't drift as the plan evolves.

/**
 * Strip Blade ({{-- --}}) and PHP block (/* *\/) comments so prose that
 * merely mentions another component ("mirrors the pattern in
 * page-manager.blade.php") doesn't trip the dependency assertions —
 * only real code references should.
 */
function stripTemplateComments(string $contents): string
{
    $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $contents);

    return preg_replace('%/\*.*?\*/%s', '', $contents);
}

$editorFiles = [
    'project-item-card.blade.php',
    'project-category-manager.blade.php',
];

foreach ($editorFiles as $name) {
    it("editor component {$name} does not import page-manager / pipeline concerns", function () use ($name) {
        $file = base_path("resources/views/livewire/{$name}");
        $contents = stripTemplateComments(file_get_contents($file));

        // These components should not be aware of the outer page-manager or
        // the orchestrator / pipeline layer. Cross-cutting behaviour belongs
        // in their own Livewire components that get mounted from page-manager.
        expect($contents)->not->toContain('PipelineOrchestrator');
        expect($contents)->not->toContain('page-manager');
        expect(stripos($contents, 'GenerateContentJob'))->toBeFalse();
    });
}

it('project-item-card depends only on ProjectItem + SiteMedia + site category vocab', function () {
    $contents = stripTemplateComments(file_get_contents(base_path('resources/views/livewire/project-item-card.blade.php')));

    // Allowed dependencies — ok to reference
    expect($contents)->toContain('ProjectItem');
    expect($contents)->toContain('GenerateProjectImageJob');  // single-item regen

    // Disallowed — cross-feature leaks
    expect($contents)->not->toContain('PageRenderer');
    expect($contents)->not->toContain('CompositionService');
    expect($contents)->not->toContain('BusinessProfile');
});
