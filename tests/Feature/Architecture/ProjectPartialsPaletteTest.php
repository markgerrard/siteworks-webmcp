<?php

// Stitch reference palette — none of these hex values should appear verbatim
// in our projects-page blade partials. All colour must route through theme tokens.
$stitchHexCodes = ['#a9200c', '#cc3a23', '#f9f9f9', '#f3f3f3', '#1a1c1c'];

$partialFiles = [
    'projects_hero.blade.php',
    'project_gallery.blade.php',
    'case_study_highlights.blade.php',
];

foreach ($partialFiles as $name) {
    it("blade partial {$name} does not hardcode Stitch palette values", function () use ($name, $stitchHexCodes) {
        $file = base_path("resources/views/site/sections/{$name}");
        $contents = file_get_contents($file);
        foreach ($stitchHexCodes as $hex) {
            expect(stripos($contents, $hex))->toBeFalse();
        }
    });
}
