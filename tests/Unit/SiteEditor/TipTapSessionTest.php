<?php

use Symfony\Component\Process\Process;

test('destroying an active TipTap session commits only pending changes', function () {
    $process = new Process([
        'node',
        '--test',
        base_path('tests/Unit/SiteEditor/TipTapSession.test.js'),
    ], base_path());

    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue($process->getErrorOutput().$process->getOutput());
});
