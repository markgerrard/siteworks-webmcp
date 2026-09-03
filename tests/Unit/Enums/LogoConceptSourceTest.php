<?php

use App\Enums\LogoConceptSource;

it('round-trips logo concept source values', function () {
    expect(LogoConceptSource::from('detected'))->toBe(LogoConceptSource::Detected)
        ->and(LogoConceptSource::from('generated'))->toBe(LogoConceptSource::Generated)
        ->and(LogoConceptSource::from('redraw'))->toBe(LogoConceptSource::Redraw)
        ->and(LogoConceptSource::from('trace'))->toBe(LogoConceptSource::Trace);
});

it('provides display labels', function () {
    expect(LogoConceptSource::Detected->label())->toBe('Detected')
        ->and(LogoConceptSource::Generated->label())->toBe('AI Concept')
        ->and(LogoConceptSource::Redraw->label())->toBe('Detected + Redrawn')
        ->and(LogoConceptSource::Trace->label())->toBe('Detected + Traced');
});
