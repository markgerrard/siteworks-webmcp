<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;

beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

function themeResolverDesignBriefFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'mood' => 'warm-traditional',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'relaxed',
        'spacing_density' => 'generous',
        'corner_style' => 'rounded',
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

test('resolve returns safe token defaults when no design brief is present', function () {
    $site = Site::factory()->create(['theme' => 'trades-bold', 'design_brief' => null]);

    $theme = $this->resolver->resolve($site, []);

    expect($theme['primary_color'])->toBe('#1e40af')
        ->and($theme['accent_color'])->toBe('#f59e0b')
        ->and($theme['surface_color'])->toBe('#ffffff')
        ->and($theme['surface_alt_color'])->toBe('#f5f5f5')
        ->and($theme['border_color'])->toBe('#e5e5e5')
        ->and($theme['text_color'])->toBe('#111111')
        ->and($theme['text_muted_color'])->toBe('#6b7280')
        ->and($theme['display_font'])->toBe('inter')
        ->and($theme['body_font'])->toBe('inter')
        ->and($theme['heading_scale'])->toBe('balanced')
        ->and($theme['spacing_density'])->toBe('balanced')
        ->and($theme['corner_style'])->toBe('soft');
});

test('resolve applies the persisted design brief token set', function () {
    $site = Site::factory()->create([
        'theme' => 'trades-bold',
        'design_brief' => themeResolverDesignBriefFixture(),
    ]);

    $theme = $this->resolver->resolve($site, []);

    expect($theme['primary_color'])->toBe('#1f3a5f')
        ->and($theme['accent_color'])->toBe('#8b6b2f')
        ->and($theme['tertiary_color'])->toBe('#f4ede0')
        ->and($theme['surface_alt_color'])->toBe('#f8f5ee')
        ->and($theme['display_font'])->toBe('fraunces')
        ->and($theme['body_font'])->toBe('source-sans-3')
        ->and($theme['spacing_density'])->toBe('generous')
        ->and($theme['corner_style'])->toBe('rounded');
});

test('brief beats extracted and legacy colours while an explicit token override still wins', function () {
    $site = Site::factory()->create([
        'theme' => 'trades-bold',
        'design_brief' => themeResolverDesignBriefFixture(),
    ]);

    $theme = $this->resolver->resolve($site, [
        'visual' => [
            'palette' => ['primary' => '#587a63', 'accent' => '#e8b4c8'],
        ],
    ], [
        'key' => 'professional-clean',
        'primary_override' => '#223344',
        'accent_override' => null,
    ]);

    expect($theme['primary_color'])->toBe('#223344')
        ->and($theme['accent_color'])->toBe('#8b6b2f')
        ->and($theme['tertiary_color'])->toBe('#f4ede0')
        ->and($theme['surface_color'])->toBe('#ffffff');
});

test('invalid design brief falls back to the legacy extraction chain', function () {
    $site = Site::factory()->create([
        'theme' => 'professional-clean',
        'design_brief' => themeResolverDesignBriefFixture([
            'mood' => 'bold-modern',
            'display_font' => 'fraunces',
        ]),
    ]);

    $theme = $this->resolver->resolve($site, []);

    expect($theme['primary_color'])->toBe('#1f2937')
        ->and($theme['accent_color'])->toBe('#6366f1')
        ->and($theme['display_font'])->toBe('inter')
        ->and($theme['body_font'])->toBe('inter');
});

test('renderTokens maps theme enums to css ready values', function () {
    $tokens = $this->resolver->renderTokens([
        'primary_color' => '#1f3a5f',
        'accent_color' => '#8b6b2f',
        'tertiary_color' => '#f4ede0',
        'surface_color' => '#ffffff',
        'surface_alt_color' => '#f8f5ee',
        'border_color' => '#e4ddcf',
        'text_color' => '#1a1a1a',
        'text_muted_color' => '#6b7280',
        'display_font' => 'fraunces',
        'body_font' => 'source-sans-3',
        'heading_scale' => 'tight',
        'spacing_density' => 'generous',
        'corner_style' => 'rounded',
    ]);

    expect($tokens['font_link_href'])->toBe('/fonts/fraunces+source-sans-3.css')
        ->and($tokens['display_font_stack'])->toBe('"Fraunces", Georgia, serif')
        ->and($tokens['body_font_stack'])->toBe('"Source Sans 3", system-ui, sans-serif')
        ->and($tokens['radius_card'])->toBe('24px')
        ->and($tokens['radius_button'])->toBe('9999px')
        ->and($tokens['section_spacing'])->toBe('8rem')
        ->and($tokens['container_width'])->toBe('1360px')
        ->and($tokens['heading_letter_spacing'])->toBe('-0.02em');
});
