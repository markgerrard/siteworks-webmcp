<?php

use App\Models\Site;
use App\Services\Site\DesignBrief;

function validDesignBriefFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'mood' => 'warm-traditional',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'relaxed',
        'spacing_density' => 'generous',
        'corner_style' => 'soft',
        'palette' => [
            'primary' => '#1f3a5f',
            'accent' => '#8b6b2f',
            'tertiary' => '#f4ede0',
            'surface' => '#ffffff',
            'surface_alt' => '#f8f5ee',
            'border' => '#e4ddcf',
            'text' => '#1a1a1a',
            'text_muted' => '#6b7280',
        ],
        'rationale' => 'Heritage-led palette and serif display fit the business tone.',
    ], $overrides);
}

test('valid brief creates a usable design brief object', function () {
    $brief = DesignBrief::fromArray(validDesignBriefFixture());

    expect($brief)->not->toBeNull();
    expect($brief?->isValid())->toBeTrue();
    expect($brief?->mood())->toBe('warm-traditional');
    expect($brief?->displayFont())->toBe('fraunces');
    expect($brief?->bodyFont())->toBe('source-sans-3');
    expect($brief?->headingScale())->toBe('relaxed');
    expect($brief?->spacingDensity())->toBe('generous');
    expect($brief?->cornerStyle())->toBe('soft');
    expect($brief?->palette()['primary'])->toBe('#1f3a5f');
    expect($brief?->rationale())->toBe('Heritage-led palette and serif display fit the business tone.');
});

test('missing mood is invalid', function () {
    $brief = new DesignBrief(validDesignBriefFixture(['mood' => null]));

    expect($brief->isValid())->toBeFalse();
    expect(DesignBrief::fromArray(validDesignBriefFixture(['mood' => null])))->toBeNull();
});

test('unknown mood is invalid', function () {
    $brief = new DesignBrief(validDesignBriefFixture(['mood' => 'neon-punk']));

    expect($brief->isValid())->toBeFalse();
});

test('serif display with bold-modern mood is invalid', function () {
    $brief = new DesignBrief(validDesignBriefFixture([
        'mood' => 'bold-modern',
        'display_font' => 'fraunces',
    ]));

    expect($brief->isValid())->toBeFalse();
});

test('serif display with warm-traditional mood is valid', function () {
    $brief = DesignBrief::fromArray(validDesignBriefFixture([
        'mood' => 'warm-traditional',
        'display_font' => 'playfair-display',
    ]));

    expect($brief)->not->toBeNull();
    expect($brief?->displayFont())->toBe('playfair-display');
});

test('palette missing text is invalid', function () {
    $data = validDesignBriefFixture();
    unset($data['palette']['text']);

    $brief = new DesignBrief($data);

    expect($brief->isValid())->toBeFalse();
});

test('palette with white text on white surface fails contrast validation', function () {
    $brief = new DesignBrief(validDesignBriefFixture([
        'palette' => [
            'text' => '#ffffff',
            'surface' => '#ffffff',
        ],
    ]));

    expect($brief->isValid())->toBeFalse();
});

test('palette with black text on white surface passes contrast validation', function () {
    $brief = DesignBrief::fromArray(validDesignBriefFixture([
        'palette' => [
            'text' => '#000000',
            'surface' => '#ffffff',
            'primary' => '#1f3a5f',
            'accent' => '#8b6b2f',
        ],
    ]));

    expect($brief)->not->toBeNull();
    expect($brief?->wcagContrastRatio('#000000', '#ffffff'))->toBe(21.0);
});

test('fromSite returns null when site design_brief is null', function () {
    $site = Site::factory()->create(['design_brief' => null]);

    expect(DesignBrief::fromSite($site))->toBeNull();
});

test('fromSite returns a design brief when site design_brief is valid', function () {
    $site = Site::factory()->create(['design_brief' => validDesignBriefFixture()]);

    $brief = DesignBrief::fromSite($site);

    expect($brief)->not->toBeNull();
    expect($brief?->mood())->toBe('warm-traditional');
});

test('toArray roundtrips through fromArray', function () {
    $brief = DesignBrief::fromArray(validDesignBriefFixture([
        'mood' => 'refined-minimal',
        'display_font' => 'dm-serif-display',
        'palette' => [
            'primary' => '#345678',
            'accent' => '#9f7842',
        ],
    ]));

    expect($brief)->not->toBeNull();

    $roundTripped = DesignBrief::fromArray($brief?->toArray() ?? []);

    expect($roundTripped)->not->toBeNull();
    expect($roundTripped?->toArray())->toBe($brief?->toArray());
});
