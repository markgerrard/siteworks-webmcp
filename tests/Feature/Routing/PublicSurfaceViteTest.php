<?php

it('public manifest excludes site-editor entry points', function () {
    $manifestPath = public_path('build-site-public/manifest.json');

    if (! file_exists($manifestPath)) {
        $this->markTestSkipped('public manifest not built; run `npm run build:site-public`');
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    $entries = array_keys($manifest);

    expect($entries)->not->toContain('resources/js/site-editor/index.js');
    expect($entries)->not->toContain('resources/css/site-editor.css');
    expect($entries)->toContain('resources/css/app.css');
});
