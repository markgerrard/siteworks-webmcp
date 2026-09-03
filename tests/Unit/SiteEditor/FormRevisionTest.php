<?php

use Symfony\Component\Process\Process;

test('the form panel resolves the shared current revision when saving', function () {
    $process = new Process([
        'node',
        '--test',
        base_path('tests/Unit/SiteEditor/FormRevision.test.js'),
    ], base_path());

    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue($process->getErrorOutput().$process->getOutput());
});
