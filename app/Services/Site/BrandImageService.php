<?php

namespace App\Services\Site;

use App\Models\Site;
use App\Models\Site\SiteVersionCurrent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickException;
use Throwable;

/**
 * Server-side favicon + Open Graph social-card generator.
 *
 * Composes SVG in-memory from the site's design_brief palette + fonts,
 * renders to PNG with Imagick, and uploads to DO Spaces under
 * `sites/{id}/brand/…`. No external generation is involved.
 *
 * Failures are non-fatal: the renderer gracefully omits favicon / OG tags
 * when the URL is null.
 */
class BrandImageService
{
    private const MAX_INPUT_BYTES = 4 * 1024 * 1024;

    private const MAX_DECODE_DIMENSION = 6000;

    private const MAX_DECODE_AREA = 36_000_000;

    private const OG_TEXT_X = 80;

    private const OG_TEXT_MAX_X = 1120;

    private const OG_NAME_SIZE_MAX = 72;

    private const OG_NAME_SIZE_MIN = 44;

    private const OG_STRAP_SIZE = 32;

    /** @var list<string> */
    private const ALLOWED_LOGO_FORMATS = ['JPEG', 'JPG', 'PNG', 'WEBP', 'SVG'];

    /** @var list<string>|null */
    private ?array $imagickFontList = null;

    public function __construct(
        protected ThemeResolver $themeResolver,
        protected PublicPageCache $pageCache,
    ) {}

    /** Default palette used when a site has no design_brief yet. */
    private const FALLBACK_PALETTE = [
        'primary' => '#1f2937',
        'accent' => '#f59e0b',
        'surface' => '#ffffff',
        'surface_alt' => '#111827',
        'text' => '#f9fafb',
        'text_muted' => '#d1d5db',
    ];

    /**
     * Map design-brief font tokens (slugs) to system fonts Imagick can
     * render without fetching web fonts. The favicon + OG are low-stakes
     * visuals; pragmatic mapping beats Dockerfile churn.
     *
     * @var array<string, string>
     */
    private const SYSTEM_FONT_MAP = [
        'fraunces' => 'Georgia',
        'dm-serif-display' => 'Georgia',
        'playfair-display' => 'Georgia',
        'space-grotesk' => 'Arial',
        'bricolage-grotesque' => 'Arial',
        'archivo-black' => 'Impact',
        'inter' => 'Arial',
        'manrope' => 'Arial',
        'figtree' => 'Arial',
        'source-sans-3' => 'Arial',
        'nunito-sans' => 'Arial',
    ];

    /**
     * Regenerate both PNGs and persist the resulting URLs on the Site.
     * Either step failing leaves the other's URL intact — no transaction
     * needed because these are advisory assets, not critical data.
     */
    public function regenerateBoth(Site $site): void
    {
        $faviconUrl = $this->generateFavicon($site);
        $ogUrl = $this->generateOgImage($site);
        $ogSquareUrl = $this->generateOgSquareImage($site);

        $updates = [];
        if ($faviconUrl !== null && $faviconUrl !== $site->brand_favicon_url) {
            $updates['brand_favicon_url'] = $faviconUrl;
        }
        if ($ogUrl !== null && $ogUrl !== $site->brand_og_url) {
            $updates['brand_og_url'] = $ogUrl;
        }
        if ($ogSquareUrl !== null && $ogSquareUrl !== $site->brand_og_square_url) {
            $updates['brand_og_square_url'] = $ogSquareUrl;
        }

        if ($updates !== []) {
            $site->update($updates);

            // Rendered pages embed these URLs, so cached HTML would keep
            // serving the previous favicon/OG until the next unrelated
            // flush. Invalidate only when a URL actually changed —
            // content-hash filenames make same-palette regenerations a
            // no-op here.
            $this->pageCache->invalidate($site);
        }
    }

    /**
     * The palette the favicon/OG should render with: the design-brief
     * palette resolved through ThemeResolver with the CURRENT PUBLISHED
     * composition's theme overrides applied — i.e. what the live site
     * actually looks like. Reading only design_brief.palette left brand
     * assets stuck on the original brief colours after an agent
     * recoloured the site in the design panel.
     *
     * @return array<string, string>
     */
    public function effectivePalette(Site $site): array
    {
        $profile = $site->businessProfile?->profile_data ?? [];
        $compositionTheme = SiteVersionCurrent::query()
            ->where('site_id', $site->id)
            ->first()
            ?->version?->composition['theme'] ?? null;

        try {
            $theme = $this->themeResolver->resolve($site, is_array($profile) ? $profile : [], $compositionTheme);
        } catch (Throwable $e) {
            Log::warning('BrandImageService: theme resolution failed, using brief palette', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return $this->briefPalette($site);
        }

        $map = [
            'primary' => 'primary_color',
            'accent' => 'accent_color',
            'surface' => 'surface_color',
            'surface_alt' => 'surface_alt_color',
            'text' => 'text_color',
            'text_muted' => 'text_muted_color',
        ];

        $out = $this->briefPalette($site);
        foreach ($map as $key => $themeKey) {
            $value = $theme[$themeKey] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Compose + upload a 512×512 favicon PNG. Returns the Spaces URL on
     * success, null on failure (caller falls back to no favicon tag).
     */
    public function generateFavicon(Site $site): ?string
    {
        try {
            $palette = $this->effectivePalette($site);
            $font = $this->resolveDisplayFont($site);
            $initials = $this->resolveInitials($site);

            $svg = $this->faviconSvg($palette, $font, $initials);
            $png = $this->renderSvgToPng($svg);

            return $this->uploadPng($site, 'favicon', $png);
        } catch (Throwable $exception) {
            Log::warning('BrandImageService::generateFavicon failed', [
                'site_id' => $site->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Compose + upload a 1200×630 Open Graph image. Returns Spaces URL
     * or null on failure.
     */
    public function generateOgImage(Site $site): ?string
    {
        return $this->generateOgVariant($site, 'og', 1200, 630);
    }

    /**
     * Compose + upload a 1200×1200 square card for platforms that crop.
     */
    public function generateOgSquareImage(Site $site): ?string
    {
        return $this->generateOgVariant($site, 'og-square', 1200, 1200);
    }

    /**
     * Card colours that stay readable on every theme: brand primary only
     * when it meets WCAG AA (4.5:1) against the site text colour,
     * otherwise a near-white surface with dark ink.
     *
     * @param  array{primary:string,accent:string,surface:string,surface_alt:string,text:string,text_muted:string}  $palette
     * @return array{background:string,text:string,text_muted:string,accent:string}
     */
    public function ogCardPalette(array $palette): array
    {
        $primary = $palette['primary'];
        $text = $palette['text'];
        $muted = $palette['text_muted'];
        $light = '#f8fafc';
        $darkText = '#111827';
        $darkMuted = '#475569';

        if ($this->themeResolver->contrastRatio($primary, $text) >= 4.5) {
            $background = $primary;
            $foreground = $text;
            $foregroundMuted = $this->themeResolver->contrastRatio($primary, $muted) >= 4.5
                ? $muted
                : ($this->themeResolver->isDarkSurface($primary) ? '#e5e7eb' : $darkMuted);
        } elseif ($this->themeResolver->contrastRatio($light, $text) >= 4.5) {
            $background = $light;
            $foreground = $text;
            $foregroundMuted = $this->themeResolver->contrastRatio($light, $muted) >= 4.5
                ? $muted
                : $darkMuted;
        } else {
            $background = $light;
            $foreground = $darkText;
            $foregroundMuted = $darkMuted;
        }

        return [
            'background' => $background,
            'text' => $foreground,
            'text_muted' => $foregroundMuted,
            'accent' => $palette['accent'],
        ];
    }

    /**
     * SVG used to rasterise the share card. Public so tests can assert
     * monogram-vs-logo markup without decoding PNG pixels.
     */
    public function composeOgSvg(Site $site, bool $square = false): string
    {
        $palette = $this->effectivePalette($site);
        $card = $this->ogCardPalette($palette);
        $hasLogo = $this->resolveLogoBytes($site) !== null;

        return $this->ogSvg(
            $card,
            $this->resolveDisplayFont($site),
            $this->resolveBodyFont($site),
            $site->business_name ?? 'Your Business',
            $this->resolveStrapline($site),
            $hasLogo,
            $square,
            $hasLogo ? null : $this->resolveInitials($site),
        );
    }

    /**
     * @return array{primary:string,accent:string,surface:string,surface_alt:string,text:string,text_muted:string}
     */
    private function briefPalette(Site $site): array
    {
        $brief = is_array($site->design_brief) ? $site->design_brief : [];
        $palette = is_array($brief['palette'] ?? null) ? $brief['palette'] : [];

        $out = self::FALLBACK_PALETTE;
        foreach ($out as $key => $default) {
            $value = $palette[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function resolveDisplayFont(Site $site): string
    {
        $brief = is_array($site->design_brief) ? $site->design_brief : [];
        $slug = is_string($brief['display_font'] ?? null) ? $brief['display_font'] : 'inter';

        return self::SYSTEM_FONT_MAP[$slug] ?? 'Arial';
    }

    private function resolveBodyFont(Site $site): string
    {
        $brief = is_array($site->design_brief) ? $site->design_brief : [];
        $slug = is_string($brief['body_font'] ?? null) ? $brief['body_font'] : 'inter';

        return self::SYSTEM_FONT_MAP[$slug] ?? 'Arial';
    }

    /**
     * 1-2 letter monogram. First letter of the first two words; for
     * single-word names, the first two letters. Falls back to the first
     * letter of business_type if the name is unusable.
     */
    private function resolveInitials(Site $site): string
    {
        $name = trim((string) ($site->business_name ?? ''));
        if ($name === '') {
            $type = trim((string) ($site->business_type ?? ''));

            return $type !== '' ? Str::upper(mb_substr($type, 0, 1)) : '?';
        }

        $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) >= 2) {
            return Str::upper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return Str::upper(mb_substr($words[0] ?? $name, 0, 2));
    }

    private function resolveStrapline(Site $site): string
    {
        $profile = $site->businessProfile?->profile_data ?? [];
        $profile = is_array($profile) ? $profile : [];

        foreach (['strapline', 'meta_description', 'summary'] as $key) {
            $candidate = $profile[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $type = trim((string) ($site->business_type ?? ''));

        return $type;
    }

    /**
     * @param  array{primary:string,accent:string,surface:string,surface_alt:string,text:string,text_muted:string}  $palette
     */
    private function faviconSvg(array $palette, string $font, string $initials): string
    {
        $initials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $font = htmlspecialchars($font, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
  <rect width="512" height="512" fill="{$palette['primary']}"/>
  <text x="256" y="320" text-anchor="middle" font-family="{$font}"
        font-size="260" fill="{$palette['surface']}" font-weight="700">{$initials}</text>
  <rect x="128" y="388" width="256" height="14" fill="{$palette['accent']}"/>
</svg>
SVG;
    }

    /**
     * @param  array{background:string,text:string,text_muted:string,accent:string}  $card
     */
    private function ogSvg(
        array $card,
        string $displayFont,
        string $bodyFont,
        string $businessName,
        string $strapline,
        bool $hasLogo,
        bool $square,
        ?string $initials,
    ): string {
        $width = 1200;
        $height = $square ? 1200 : 630;
        $nameFit = $this->fitOgName($businessName, $displayFont);
        $strapLines = $this->fitOgStrapline($strapline, $bodyFont);
        $escapedDisplayFont = htmlspecialchars($displayFont, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $nameY = $hasLogo ? ($square ? 520 : 360) : ($square ? 420 : 280);
        $nameLineHeight = (int) round($nameFit['size'] * 1.15);
        $nameLastY = $nameY + (max(count($nameFit['lines']) - 1, 0) * $nameLineHeight);
        $strapY = $nameLastY + (count($nameFit['lines']) > 1 ? 56 : 90);
        $strapLineHeight = (int) round(self::OG_STRAP_SIZE * 1.2);
        $strapLastY = $strapY + (max(count($strapLines) - 1, 0) * $strapLineHeight);
        $ruleY = ($strapLines === [] ? $nameLastY : $strapLastY) + 40;
        $monogram = '';
        if (! $hasLogo && is_string($initials) && $initials !== '') {
            $mark = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $monoY = $square ? 280 : 180;
            $monogram = <<<SVG
  <text x="80" y="{$monoY}" font-family="{$escapedDisplayFont}" font-size="72" fill="{$card['text']}" font-weight="700">{$mark}</text>
SVG;
        }

        $nameSvg = $this->ogSvgText(self::OG_TEXT_X, $nameY, $displayFont, $nameFit['size'], $card['text'], 700, $nameFit['lines']);
        $strapSvg = $this->ogSvgText(self::OG_TEXT_X, $strapY, $bodyFont, self::OG_STRAP_SIZE, $card['text_muted'], 400, $strapLines);

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="{$width}" height="{$height}">
  <rect width="{$width}" height="{$height}" fill="{$card['background']}"/>
{$monogram}
{$nameSvg}
{$strapSvg}
  <rect x="80" y="{$ruleY}" width="180" height="6" fill="{$card['accent']}"/>
</svg>
SVG;
    }

    /**
     * @return array{size: int, lines: list<string>}
     */
    private function fitOgName(string $name, string $font): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['size' => self::OG_NAME_SIZE_MAX, 'lines' => []];
        }

        $maxWidth = $this->ogTextMaxWidth();
        $size = self::OG_NAME_SIZE_MAX;
        while ($size > self::OG_NAME_SIZE_MIN && $this->measureTextWidth($name, $font, $size, 700) > $maxWidth) {
            $size--;
        }

        if ($this->measureTextWidth($name, $font, $size, 700) <= $maxWidth) {
            return ['size' => $size, 'lines' => [$name]];
        }

        return ['size' => $size, 'lines' => $this->wrapText($name, $font, $size, 700, $maxWidth, 2)];
    }

    /**
     * @return list<string>
     */
    private function fitOgStrapline(string $strapline, string $font): array
    {
        $strapline = trim($strapline);
        if ($strapline === '') {
            return [];
        }

        return $this->wrapText($strapline, $font, self::OG_STRAP_SIZE, 400, $this->ogTextMaxWidth(), 2);
    }

    private function ogTextMaxWidth(): float
    {
        return (self::OG_TEXT_MAX_X - self::OG_TEXT_X) - 8;
    }

    /**
     * @return list<string>
     */
    private function wrapText(string $text, string $font, int $size, int $weight, float $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [];
        }

        $lines = [];
        $current = '';

        foreach ($words as $index => $word) {
            if (count($lines) === $maxLines - 1) {
                $rest = ($current === '' ? '' : $current.' ').implode(' ', array_slice($words, $index));

                $lines[] = $this->ellipsizeToWidth($rest, $font, $size, $weight, $maxWidth);

                return $lines;
            }

            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->measureTextWidth($candidate, $font, $size, $weight) <= $maxWidth) {
                $current = $candidate;

                continue;
            }

            if ($current === '') {
                $lines[] = $this->ellipsizeToWidth($word, $font, $size, $weight, $maxWidth);
                $current = '';

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function ellipsizeToWidth(string $text, string $font, int $size, int $weight, float $maxWidth): string
    {
        $text = trim($text);
        if ($text === '' || $this->measureTextWidth($text, $font, $size, $weight) <= $maxWidth) {
            return $text;
        }

        $ellipsis = '…';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lo = 1;
        $hi = count($chars);
        $best = $ellipsis;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $candidate = implode('', array_slice($chars, 0, $mid)).$ellipsis;
            if ($this->measureTextWidth($candidate, $font, $size, $weight) <= $maxWidth) {
                $best = $candidate;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best;
    }

    private function measureTextWidth(string $text, string $font, int $size, int $weight): float
    {
        if ($text === '') {
            return 0.0;
        }

        try {
            $draw = new \ImagickDraw;
            $draw->setFont($this->imagickFont($font, $weight));
            $draw->setFontSize($size);
            $draw->setFontWeight($weight);

            $canvas = new Imagick;
            $canvas->newImage(1, 1, new \ImagickPixel('white'));
            $metrics = $canvas->queryFontMetrics($draw, $text);
            $canvas->clear();
        } catch (Throwable) {
            return $size * 0.7 * mb_strlen($text);
        }

        $advance = (float) ($metrics['textWidth'] ?? 0);
        $bound = (float) ($metrics['boundingBox']['x2'] ?? 0);

        return max($advance, $bound);
    }

    /**
     * CSS family names in the SVG (Arial/Georgia/Impact) are not always
     * in Imagick's font list. queryFontMetrics needs a configured face;
     * missing families fall back to Helvetica, matching the SVG rasteriser.
     */
    private function imagickFont(string $family, int $weight): string
    {
        $fonts = $this->imagickFontList ??= (new Imagick)->queryFonts();
        $bold = $weight >= 700;
        $candidates = $bold
            ? [$family.'-Bold', $family.' Bold', $family]
            : [$family, $family.'-Regular', $family.' Regular'];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $fonts, true)) {
                return $candidate;
            }
        }

        $fallback = $bold ? 'Helvetica-Bold' : 'Helvetica';

        return in_array($fallback, $fonts, true) ? $fallback : ($fonts[0] ?? $family);
    }

    /**
     * @param  list<string>  $lines
     */
    private function ogSvgText(
        int $x,
        int $y,
        string $font,
        int $size,
        string $fill,
        int $weight,
        array $lines,
    ): string {
        if ($lines === []) {
            return '';
        }

        $font = htmlspecialchars($font, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $weightAttr = $weight >= 700 ? ' font-weight="700"' : '';
        $lineHeight = (int) round($size * 1.15);
        $spans = [];
        foreach ($lines as $i => $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $dy = $i === 0 ? 0 : $lineHeight;
            $spans[] = "    <tspan x=\"{$x}\" dy=\"{$dy}\">{$escaped}</tspan>";
        }
        $inner = implode("\n", $spans);

        return <<<SVG
  <text x="{$x}" y="{$y}" font-family="{$font}" font-size="{$size}" fill="{$fill}"{$weightAttr}>
{$inner}
  </text>
SVG;
    }

    private function generateOgVariant(Site $site, string $kind, int $width, int $height): ?string
    {
        try {
            $square = $height === $width;
            $svg = $this->composeOgSvg($site, $square);
            $png = $this->renderSvgToPng($svg);
            $logo = $this->resolveLogoBytes($site);
            if ($logo !== null) {
                $png = $this->compositeLogo($png, $logo, $width, $height);
            }

            return $this->uploadPng($site, $kind, $png);
        } catch (Throwable $exception) {
            Log::warning('BrandImageService::generateOgVariant failed', [
                'site_id' => $site->id,
                'kind' => $kind,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveLogoBytes(Site $site): ?string
    {
        $site->loadMissing('selectedLogoConcept');
        $path = $site->selectedLogoConcept?->path;
        if (! is_string($path) || $path === '') {
            $path = $site->brand_image_path;
        }
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $bytes = Storage::disk('s3')->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        try {
            return $this->validatedLogoBytes($bytes);
        } catch (Throwable $exception) {
            Log::warning('BrandImageService: unsafe logo skipped', [
                'site_id' => $site->id,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Contain-fit the logo onto the rendered card: max 40% of card width.
     */
    private function compositeLogo(string $png, string $logoBytes, int $canvasWidth, int $canvasHeight): string
    {
        $canvas = new Imagick;
        $logo = new Imagick;
        $prior = [];

        try {
            $this->lowerResourceLimits($canvas, $prior);
            $canvas->readImageBlob($png);
            $canvas->setImageFormat('png');

            $logo->setBackgroundColor(new \ImagickPixel('transparent'));
            $logo->readImageBlob($logoBytes);
            $logo->setIteratorIndex(0);
            $logo->setImageFormat('png');

            $maxWidth = (int) floor($canvasWidth * 0.4);
            $maxHeight = (int) floor($canvasHeight * 0.32);
            $logo->thumbnailImage(max(1, $maxWidth), max(1, $maxHeight), true);

            $canvas->compositeImage($logo, Imagick::COMPOSITE_OVER, 80, 80);
            $canvas->stripImage(); // deterministic bytes: no timestamps/text chunks → stable content hash

            return $canvas->getImageBlob();
        } finally {
            $this->restoreResourceLimits($canvas, $prior);
            $logo->clear();
            $canvas->clear();
        }
    }

    /**
     * @throws ImagickException
     */
    private function renderSvgToPng(string $svg): string
    {
        $imagick = new Imagick;
        $prior = [];

        try {
            $this->lowerResourceLimits($imagick, $prior);
            $imagick->setBackgroundColor('transparent');
            $imagick->readImageBlob($svg);
            $imagick->setImageFormat('png');
            $imagick->stripImage();

            return $imagick->getImageBlob();
        } finally {
            $this->restoreResourceLimits($imagick, $prior);
            $imagick->clear();
        }
    }

    /**
     * Decode a Design-panel custom share image under the same Imagick
     * resource limits as logo compositing. Returns the sniffed extension
     * and the real pixel size for og:image:width/height.
     *
     * @return array{extension: string, width: int, height: int}
     */
    public function validatedCustomShareImage(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('The share image must be a valid PNG, JPEG, or WebP image.');
        }

        $magic = substr($bytes, 0, 12);
        $hasAllowedMagic = str_starts_with($magic, "\xFF\xD8\xFF")
            || str_starts_with($magic, "\x89PNG\r\n\x1A\n")
            || (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP');
        if (! $hasAllowedMagic) {
            throw new \RuntimeException('The share image must be a valid PNG, JPEG, or WebP image.');
        }

        $image = new Imagick;
        $prior = [];

        try {
            $this->lowerResourceLimits($image, $prior);
            $image->readImageBlob($bytes);
            $format = strtoupper($image->getImageFormat());
            $extension = match ($format) {
                'PNG' => 'png',
                'JPEG', 'JPG' => 'jpg',
                'WEBP' => 'webp',
                default => null,
            };
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($extension === null || $image->getNumberImages() !== 1) {
                throw new \RuntimeException('The share image could not be decoded as a PNG, JPEG, or WebP image.');
            }
            if ($width < 600 || $height < 315 || $width > self::MAX_DECODE_DIMENSION || $height > self::MAX_DECODE_DIMENSION) {
                throw new \RuntimeException('The share image must be between 600×315 and 6000×6000 pixels.');
            }

            return ['extension' => $extension, 'width' => $width, 'height' => $height];
        } catch (ImagickException $exception) {
            throw new \RuntimeException('The share image could not be decoded as a PNG, JPEG, or WebP image.', 0, $exception);
        } finally {
            $this->restoreResourceLimits($image, $prior);
            $image->clear();
        }
    }

    private function validatedLogoBytes(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Logo exceeds the 4 MB byte limit.');
        }

        $isSvg = preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i', $bytes) === 1;
        if ($isSvg) {
            $bytes = $this->sanitizeSvg($bytes);
        } else {
            $magic = substr($bytes, 0, 12);
            $allowed = str_starts_with($magic, "\xFF\xD8\xFF")
                || str_starts_with($magic, "\x89PNG\r\n\x1A\n")
                || (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP');
            if (! $allowed) {
                throw new \RuntimeException('Logo format is not allowed.');
            }
        }

        $image = new Imagick;
        $prior = [];

        try {
            $this->lowerResourceLimits($image, $prior);
            $image->readImageBlob($bytes);
            $format = strtoupper($image->getImageFormat());
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if (! in_array($format, self::ALLOWED_LOGO_FORMATS, true)) {
                throw new \RuntimeException("Logo format {$format} is not allowed.");
            }
            if ($image->getNumberImages() !== 1) {
                throw new \RuntimeException('Animated logos are not allowed.');
            }
            if ($width < 1 || $height < 1 || $width > self::MAX_DECODE_DIMENSION || $height > self::MAX_DECODE_DIMENSION) {
                throw new \RuntimeException("Logo dimensions {$width}×{$height} are outside the decode limits.");
            }
        } finally {
            $this->restoreResourceLimits($image, $prior);
            $image->clear();
        }

        return $bytes;
    }

    private function sanitizeSvg(string $svg): string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY|<\s*script\b|<\s*foreignObject\b|(?:xlink:)?href\s*=\s*["\']\s*(?:https?:|\/\/|file:|data:)/i', $svg) === 1) {
            throw new \RuntimeException('SVG contains unsafe active or external content.');
        }

        $sanitized = preg_replace('/\s(?:xlink:)?href\s*=\s*(["\']).*?\1/is', '', $svg);
        if (! is_string($sanitized)) {
            throw new \RuntimeException('SVG could not be sanitized.');
        }

        return $sanitized;
    }

    /**
     * @param  array<int, int|float>  $prior
     */
    private function lowerResourceLimits(Imagick $image, array &$prior): void
    {
        $requested = [
            Imagick::RESOURCETYPE_AREA => self::MAX_DECODE_AREA,
            Imagick::RESOURCETYPE_WIDTH => self::MAX_DECODE_DIMENSION,
            Imagick::RESOURCETYPE_HEIGHT => self::MAX_DECODE_DIMENSION,
            Imagick::RESOURCETYPE_MEMORY => 128 * 1024 * 1024,
        ];
        if (defined(Imagick::class.'::RESOURCETYPE_TIME')) {
            $requested[Imagick::RESOURCETYPE_TIME] = 10;
        }

        foreach ($requested as $type => $limit) {
            $prior[$type] = $image->getResourceLimit($type);
            $unlimitedTime = defined(Imagick::class.'::RESOURCETYPE_TIME')
                && $type === Imagick::RESOURCETYPE_TIME
                && (float) $prior[$type] === 0.0;
            if ($unlimitedTime || $limit < $prior[$type]) {
                $image->setResourceLimit($type, $limit);
            }
        }
    }

    /** @param array<int, int|float> $prior */
    private function restoreResourceLimits(Imagick $image, array $prior): void
    {
        foreach ($prior as $type => $limit) {
            $image->setResourceLimit($type, $limit);
        }
    }

    /**
     * Upload PNG to Spaces under sites/{id}/brand/{kind}-{hash}.png.
     * The hash suffix busts CF's edge cache whenever the brief changes.
     */
    private function uploadPng(Site $site, string $kind, string $png): ?string
    {
        $hash = substr(sha1($png), 0, 8);
        $path = sprintf('sites/%d/brand/%s-%s.png', $site->id, $kind, $hash);
        $disk = Storage::disk('s3');

        $stored = $disk->put($path, $png, [
            'visibility' => 'public',
            'CacheControl' => 'max-age=86400, public',
            'ContentType' => 'image/png',
        ]);
        if ($stored !== true || ! $disk->exists($path)) {
            throw new \RuntimeException("Object storage did not persist {$path}.");
        }

        return $disk->url($path);
    }
}
