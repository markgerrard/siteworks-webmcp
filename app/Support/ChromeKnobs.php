<?php

namespace App\Support;

use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Support\Site\SitePublicObject;

final class ChromeKnobs
{
    /** @var list<string> */
    public const NAV_CONTAINER_STYLES = ['none', 'pill', 'plate', 'band'];

    /** @var list<string> */
    public const NAV_CONTAINER_FILLS = ['surface', 'glass', 'brand', 'pattern'];

    /** @var list<string> */
    public const HERO_COPY_STYLES = ['preset', 'plain', 'panel', 'boxed'];

    /**
     * Effective chrome recipe for the site. Classic / unknown / unusable
     * keys fall back to config/site_chrome_layouts.php `classic`.
     *
     * @return array<string, mixed>
     */
    public static function recipe(Site $site): array
    {
        $resolved = app(PageLayoutRegistry::class)->resolveKey($site, 'chrome', self::chromeKey($site));
        if (is_array($resolved)) {
            return $resolved;
        }

        $classic = config('site_chrome_layouts.classic');

        return is_array($classic) ? $classic : ['layout' => 'standard'];
    }

    public static function chromeKey(Site $site): string
    {
        $key = $site->chrome_layout ?? 'classic';
        if ($key instanceof \BackedEnum) {
            $key = $key->value;
        }

        return is_string($key) && $key !== '' ? $key : 'classic';
    }

    public static function layout(Site $site): string
    {
        return (self::recipe($site)['layout'] ?? 'standard') === 'centred' ? 'centred' : 'standard';
    }

    public static function headerMode(Site $site): string
    {
        if (self::layout($site) === 'centred') {
            return 'solid';
        }

        return self::pick($site->header_mode, ['solid', 'overlay'], 'solid');
    }

    public static function rightAction(Site $site): string
    {
        return self::pick($site->right_action, ['phone', 'cta', 'phone_cta', 'none'], 'phone');
    }

    public static function navCtaTarget(Site $site): string
    {
        return self::pick($site->nav_cta_target, ['url', 'form'], 'url');
    }

    public static function formStyle(Site $site): string
    {
        return self::pick($site->form_style, ['boxed', 'underline'], 'boxed');
    }

    public static function accentStyle(Site $site): string
    {
        return self::pick($site->accent_style, ['default', 'italic'], 'default');
    }

    public static function headerFit(Site $site): string
    {
        return self::pick($site->header_fit, ['comfortable', 'tight'], 'comfortable');
    }

    public static function overlayInnerScale(Site $site): string
    {
        return self::pick($site->overlay_inner_scale, ['overlay', 'main'], 'overlay');
    }

    public static function headerShrink(Site $site): string
    {
        if (self::layout($site) === 'centred') {
            return (self::recipe($site)['sticky_shrink'] ?? 'on') === 'off' ? 'off' : 'on';
        }

        return self::pick($site->header_shrink, ['on', 'off'], 'on');
    }

    public static function navCase(Site $site): string
    {
        if (self::layout($site) === 'centred' && (self::recipe($site)['nav_case'] ?? 'default') === 'caps') {
            return 'caps';
        }

        return self::pick($site->nav_case, ['normal', 'upper', 'lower'], 'normal');
    }

    public static function storeControls(Site $site): string
    {
        return (self::recipe($site)['store_controls'] ?? 'icons') === 'icons+labels' ? 'icons+labels' : 'icons';
    }

    /** plain = bare icon/label on the header colour (today); pill = solid rounded chip so controls stay legible over a brand image. */
    public static function storeControlStyle(Site $site): string
    {
        return (self::recipe($site)['store_control_style'] ?? 'plain') === 'pill' ? 'pill' : 'plain';
    }

    /** inline = search/cart trail the nav links (today); right = they take the header's right slot, spaced from the links. Standard layout only. */
    public static function storeControlsSlot(Site $site): string
    {
        return (self::recipe($site)['store_controls_slot'] ?? 'inline') === 'right' ? 'right' : 'inline';
    }

    /** link = today's Shop href (byte-identical); dropdown / mega expand categories at render. */
    public static function shopNavStyle(Site $site): string
    {
        if (is_string($site->shop_nav_style) && in_array($site->shop_nav_style, ['link', 'dropdown', 'mega'], true)) {
            return $site->shop_nav_style;
        }

        $value = self::recipe($site)['shop_nav_style'] ?? 'link';

        return in_array($value, ['link', 'dropdown', 'mega'], true) ? $value : 'link';
    }

    public static function navContainerStyle(Site $site): string
    {
        return self::siteThenRecipe(
            $site->nav_container_style,
            self::recipe($site)['nav_container_style'] ?? null,
            self::NAV_CONTAINER_STYLES,
            self::NAV_CONTAINER_STYLES[0],
        );
    }

    public static function navContainerFill(Site $site): string
    {
        return self::siteThenRecipe(
            $site->nav_container_fill,
            self::recipe($site)['nav_container_fill'] ?? null,
            self::NAV_CONTAINER_FILLS,
            self::NAV_CONTAINER_FILLS[0],
        );
    }

    public static function navContainerRenderStyle(Site $site): string
    {
        $style = self::navContainerStyle($site);

        if ($style === 'band' && (self::recipe($site)['nav_row'] ?? 'inline') !== 'beneath') {
            return 'plate';
        }

        return $style;
    }

    public static function navContainerCss(Site $site): string
    {
        $fill = self::navContainerFill($site);

        if ($fill === 'glass') {
            return '--nav-container-bg: color-mix(in srgb, var(--color-surface) 72%, transparent); --nav-container-ink: var(--color-text); position: relative; isolation: isolate; background-color: transparent; color: var(--nav-container-ink);';
        }

        if ($fill === 'brand') {
            return '--nav-container-bg: var(--brand-primary); --nav-container-ink: var(--color-text-on-primary); background-color: var(--nav-container-bg); color: var(--nav-container-ink);';
        }

        if ($fill === 'pattern') {
            $imageUrl = self::brandImageUrl($site);
            $pattern = $imageUrl === null
                ? 'radial-gradient(circle at 12px 12px, color-mix(in srgb, var(--brand-primary) 22%, transparent) 1.5px, transparent 1.6px)'
                : "url('".str_replace(["\\", "'"], ["\\\\", "\\'"], $imageUrl)."')";
            $size = $imageUrl === null ? '24px 24px' : (self::brandImageFit($site) === 'tile' ? 'auto' : 'cover');

            return '--nav-container-bg: var(--color-surface); --nav-container-ink: var(--color-text); background-color: var(--nav-container-bg); background-image: linear-gradient(color-mix(in srgb, var(--color-surface) 78%, transparent), color-mix(in srgb, var(--color-surface) 78%, transparent)), '.$pattern.'; background-position: center; background-size: '.$size.'; color: var(--nav-container-ink);';
        }

        return '--nav-container-bg: var(--color-surface); --nav-container-ink: var(--color-text); background-color: var(--nav-container-bg); color: var(--nav-container-ink);';
    }

    public static function navContainerClass(Site $site): string
    {
        return match (self::navContainerRenderStyle($site)) {
            'pill' => 'rounded-full shadow-sm ring-1 ring-black/10',
            'plate' => 'rounded-[var(--radius-card)] shadow-sm ring-1 ring-black/10',
            'band' => 'shadow-sm ring-1 ring-black/10',
            default => '',
        };
    }

    public static function logoHeight(Site $site): string
    {
        $value = self::recipe($site)['logo_height'] ?? 'md';

        return in_array($value, ['sm', 'md', 'lg', 'xl'], true) ? $value : 'md';
    }

    /**
     * Headline treatment on home and inner-page intro heroes.
     * preset (default) = whatever the layout recipe stamps today; plain / panel / boxed override it.
     */
    public static function heroCopyStyle(Site $site): string
    {
        return self::pick($site->hero_copy_style, self::HERO_COPY_STYLES, self::HERO_COPY_STYLES[0]);
    }

    /**
     * Effective hero section variant after the site knob. Knob first, layout
     * stamp second: preset keeps $sectionVariant; plain/panel/boxed override it.
     */
    public static function heroCopyVariant(Site $site, mixed $sectionVariant): ?string
    {
        return match (self::heroCopyStyle($site)) {
            'plain' => null,
            'panel' => 'panel-left',
            'boxed' => 'boxed-left',
            default => is_string($sectionVariant) && $sectionVariant !== '' ? $sectionVariant : null,
        };
    }

    /**
     * Scene overlay after the copy knob. Explicit panel/boxed force their
     * painted treatments; preset/plain keep the scene's overlay_style so
     * recipe gradient|none|panel output stays byte-identical.
     */
    public static function heroSceneOverlayStyle(Site $site, mixed $sceneOverlayStyle): string
    {
        $resolved = in_array($sceneOverlayStyle, ['gradient', 'none'], true)
            ? $sceneOverlayStyle
            : 'panel';

        return match (self::heroCopyStyle($site)) {
            'panel' => 'panel',
            'boxed' => 'boxed',
            default => $resolved,
        };
    }

    /** full = edge-to-edge hero (today); boxed = hero inset in the shell container with card radius. */
    public static function heroFrame(Site $site): string
    {
        $value = self::recipe($site)['hero_frame'] ?? 'full';

        return in_array($value, ['full', 'boxed'], true) ? $value : 'full';
    }

    /** Corner treatment of a boxed hero: card (site radius, default) or square. */
    public static function heroCorners(Site $site): string
    {
        $value = self::recipe($site)['hero_corners'] ?? 'card';

        return in_array($value, ['card', 'square'], true) ? $value : 'card';
    }

    /** Band behind a boxed hero: page (transparent, default), white, surface-alt, or the brand primary. */
    public static function heroBackdrop(Site $site): string
    {
        $value = self::recipe($site)['hero_backdrop'] ?? 'page';

        return in_array($value, ['page', 'white', 'surface-alt', 'primary'], true) ? $value : 'page';
    }

    /** CSS colour for heroBackdrop(); null for page. */
    public static function heroBackdropCss(Site $site): ?string
    {
        return match (self::heroBackdrop($site)) {
            'white' => '#ffffff',
            'surface-alt' => 'var(--color-surface-alt)',
            'primary' => 'var(--color-primary)',
            default => null,
        };
    }

    /** Subtle pattern layer behind the centred brand row: none (default), swirl, dots, image. */
    public static function brandPattern(Site $site): string
    {
        $value = self::recipe($site)['brand_pattern'] ?? 'none';
        if (! in_array($value, ['none', 'swirl', 'dots', 'image'], true)) {
            return 'none';
        }
        if ($value === 'image' && self::brandImageUrl($site) === null) {
            return 'none';
        }

        return $value;
    }

    public static function brandImageUrl(Site $site): ?string
    {
        $path = $site->brand_image_path;
        if (! is_string($path) || $path === '') {
            return null;
        }

        return SitePublicObject::url($path);
    }

    public static function brandImageOpacity(Site $site): float
    {
        $raw = $site->brand_image_opacity;
        $percent = is_numeric($raw) ? (int) $raw : 12;

        return max(0, min(100, $percent)) / 100;
    }

    public static function brandImageFit(Site $site): string
    {
        return self::pick($site->brand_image_fit, ['cover', 'tile'], 'cover');
    }

    /**
     * Vertical focal point (0-100) for the cover-fit brand image — a wide
     * generated texture crops to a short band in the header, and "center"
     * can hide the motif rows (Camino's rolling pins). Null /
     * out-of-range falls back to 50 (today's centred behaviour).
     */
    public static function brandImagePositionY(Site $site): int
    {
        $raw = $site->brand_image_position_y;

        return is_numeric($raw) ? max(0, min(100, (int) $raw)) : 50;
    }

    public static function overlayGlass(Site $site): string
    {
        return self::pick($site->overlay_glass, ['off', 'scrolled', 'floating', 'always'], 'off');
    }

    /** Hex override for the nav-link strip; null keeps today's header_bg paint. */
    public static function navRowBg(Site $site): ?string
    {
        return self::hexColour($site->nav_row_bg);
    }

    /** Subtle pattern layer behind the nav links: none (default), swirl, dots, image. */
    public static function navRowPattern(Site $site): string
    {
        $value = self::siteThenRecipe(
            $site->nav_row_pattern,
            self::recipe($site)['nav_row_pattern'] ?? null,
            ['none', 'swirl', 'dots', 'image'],
            'none',
        );
        if ($value === 'image' && self::navRowImageUrl($site) === null) {
            return 'none';
        }

        return $value;
    }

    public static function navRowImageUrl(Site $site): ?string
    {
        return self::publicImageUrl($site->nav_row_image_path);
    }

    public static function navRowImageOpacity(Site $site): float
    {
        return self::imageOpacity($site->nav_row_image_opacity);
    }

    public static function navRowImageFit(Site $site): string
    {
        return self::pick($site->nav_row_image_fit, ['cover', 'tile'], 'cover');
    }

    public static function navRowImagePositionY(Site $site): int
    {
        return self::imagePositionY($site->nav_row_image_position_y);
    }

    /**
     * Accent-colour rule at the bottom of the nav row, mirroring the shop hero's.
     * Modes: off (default), on, no_hero (suppressed on pages whose content starts
     * with a hero, so the hero's own accent rule never doubles up).
     */
    public static function navRowAccentBorder(Site $site): string
    {
        return self::pick($site->nav_row_accent_border, ['off', 'on', 'no_hero'], 'off');
    }

    /**
     * Link/pill contrast for the nav strip. Uses nav_row_bg when set,
     * otherwise the same header_bg luminance path the blades use today.
     */
    public static function navRowIsDark(Site $site): bool
    {
        $hex = self::navRowBg($site) ?? self::hexColour($site->header_bg) ?? '#ffffff';

        return self::hexIsDark($hex);
    }

    public static function announcementEnabled(Site $site): bool
    {
        return (bool) $site->announcement_enabled;
    }

    /**
     * @return list<array{text: string, url?: string}>
     */
    public static function announcementMessages(Site $site): array
    {
        $raw = $site->announcement_messages;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (count($out) >= 5) {
                break;
            }
            if (is_string($item)) {
                $text = trim($item);
                $url = null;
            } elseif (is_array($item)) {
                $text = is_string($item['text'] ?? null) ? trim($item['text']) : '';
                $url = NavCta::safeUrl(is_string($item['url'] ?? null) ? $item['url'] : null);
            } else {
                continue;
            }
            if ($text === '' || mb_strlen($text) > 120) {
                continue;
            }

            $entry = ['text' => $text];
            if ($url !== null) {
                $entry['url'] = $url;
            }
            $out[] = $entry;
        }

        return $out;
    }

    public static function announcementBg(Site $site): ?string
    {
        return self::hexColour($site->announcement_bg);
    }

    public static function announcementIsDark(Site $site): bool
    {
        $hex = self::announcementBg($site);

        return $hex !== null && self::hexIsDark($hex);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function pick(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function siteThenRecipe(mixed $siteValue, mixed $recipeValue, array $allowed, string $default): string
    {
        if (is_string($siteValue) && in_array($siteValue, $allowed, true)) {
            return $siteValue;
        }

        return self::pick($recipeValue, $allowed, $default);
    }

    private static function hexColour(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return null;
        }

        return strtolower($value);
    }

    private static function hexIsDark(string $hex): bool
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255 < 0.5;
    }

    private static function publicImageUrl(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return SitePublicObject::url($path);
    }

    private static function imageOpacity(mixed $raw): float
    {
        $percent = is_numeric($raw) ? (int) $raw : 12;

        return max(0, min(100, $percent)) / 100;
    }

    private static function imagePositionY(mixed $raw): int
    {
        return is_numeric($raw) ? max(0, min(100, (int) $raw)) : 50;
    }
}
