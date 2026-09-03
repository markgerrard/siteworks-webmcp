<?php

use App\Services\Site\Editor\OperationRegistry;

it('does not advertise generate_image, generate_logo_concepts, regenerate_hero, or manage_video', function () {
    $names = array_keys(app(OperationRegistry::class)->all());

    expect($names)->not->toContain('generate_image')
        ->and($names)->not->toContain('generate_logo_concepts')
        ->and($names)->not->toContain('regenerate_hero')
        ->and($names)->not->toContain('manage_video');
});
