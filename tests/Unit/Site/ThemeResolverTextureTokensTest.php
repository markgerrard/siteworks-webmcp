<?php

use App\Models\Site;
use App\Services\Site\ThemeResolver;

beforeEach(fn () => $this->resolver = app(ThemeResolver::class));

function textureTokenSite(int $id = 9): Site
{
    $site = new Site;
    $site->id = $id;

    return $site;
}

test('set_section_style texture tokens accept the whitelist matrix', function (array $tokens) {
    $patch = $this->resolver->validateTokenOverridePatch($tokens, allowTextureTokens: true, site: textureTokenSite());

    expect($patch['ok'] ?? false)->toBeTrue()
        ->and($patch['set'])->toBe($tokens);
})->with([
    'library key' => [['texture' => 'herringbone']],
    'none' => [['texture' => 'none']],
    'image' => [['texture' => 'image']],
    'opacity in range' => [['texture_opacity' => '0.12']],
    'size sm' => [['texture_size' => 'sm']],
    'size md' => [['texture_size' => 'md']],
    'size lg' => [['texture_size' => 'lg']],
    'image mode tile' => [['texture_image_mode' => 'tile']],
    'image mode cover' => [['texture_image_mode' => 'cover']],
    'image path in site space' => [['texture_image_path' => 'sites/9/library/bg.webp']],
]);

test('clamped opacity is stored at the range edge', function () {
    $high = $this->resolver->validateTokenOverridePatch(
        ['texture_opacity' => '0.9'],
        allowTextureTokens: true,
        site: textureTokenSite(),
    );
    $low = $this->resolver->validateTokenOverridePatch(
        ['texture_opacity' => '0.001'],
        allowTextureTokens: true,
        site: textureTokenSite(),
    );

    expect($high['set']['texture_opacity'])->toBe('0.5')
        ->and($low['set']['texture_opacity'])->toBe('0.01');
});

test('set_section_style texture tokens reject unknown keys and values loudly', function (array $tokens, string $needle) {
    $patch = $this->resolver->validateTokenOverridePatch($tokens, allowTextureTokens: true, site: textureTokenSite());

    expect($patch['ok'] ?? true)->toBeFalse()
        ->and($patch['message'] ?? '')->toContain($needle);
})->with([
    'unknown key' => [['texture_blend' => 'multiply'], 'Unknown token'],
    'unknown texture' => [['texture' => 'swirl'], 'Invalid'],
    'bad size' => [['texture_size' => 'xl'], 'Invalid'],
    'bad mode' => [['texture_image_mode' => 'stretch'], 'Invalid'],
    'non-numeric opacity' => [['texture_opacity' => 'thin'], 'Invalid'],
    'path traversal' => [['texture_image_path' => 'sites/9/../8/bg.webp'], 'Invalid'],
    'foreign site path' => [['texture_image_path' => 'sites/8/library/bg.webp'], 'Invalid'],
    'absolute url' => [['texture_image_path' => 'https://evil.example/x.webp'], 'Invalid'],
]);

test('texture tokens are rejected on the site-wide theme token seam', function () {
    $patch = $this->resolver->validateTokenOverridePatch(['texture' => 'dots']);

    expect($patch['ok'] ?? true)->toBeFalse()
        ->and($patch['message'] ?? '')->toContain('Unknown token [texture]');
});

test('null texture tokens are scheduled for removal', function () {
    $patch = $this->resolver->validateTokenOverridePatch(
        ['texture' => null, 'texture_opacity' => null],
        allowTextureTokens: true,
        site: textureTokenSite(),
    );

    expect($patch['ok'] ?? false)->toBeTrue()
        ->and($patch['remove'])->toBe(['texture', 'texture_opacity'])
        ->and($patch['set'])->toBe([]);
});
