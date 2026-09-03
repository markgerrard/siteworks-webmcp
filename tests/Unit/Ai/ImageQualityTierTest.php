<?php

use App\Enums\ImageQualityTier;

test('tier values match string enum cases used in DB column', function () {
    expect(ImageQualityTier::Preview->value)->toBe('preview');
    expect(ImageQualityTier::Production->value)->toBe('production');
});
