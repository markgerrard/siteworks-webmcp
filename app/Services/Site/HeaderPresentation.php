<?php

namespace App\Services\Site;

use App\Enums\LogoSize;
use App\Models\GeneratedPage;
use App\Models\Site;

final class HeaderPresentation
{
    /**
     * Inner-bar heights (mobile / md) shared by nav.blade.php class strings
     * and overlayHeaderHeight() / overlayHeaderHeightMobile() (unscrolled cells) for copy clearance.
     * Values are complete Tailwind class literals so the scanner can recover them.
     *
     * @var array<string, array{unscrolled: array{mobile: string, md: string}, scrolled: array{mobile: string, md: string}}>
     */
    /**
     * header_fit = tight: the bar is the logo box plus 1rem (0.5rem each side)
     * instead of the comfortable matrix above. Rows are the logo size classes
     * in nav.blade.php + 1rem; every value is in the site bundle via
     * @source inline.
     */
    public const HEADER_HEIGHTS_TIGHT = [
        'large' => [
            'unscrolled' => ['mobile' => 'h-[6.46875rem]', 'md' => 'md:h-[9.75rem]'],
            'scrolled' => ['mobile' => 'h-[5.9375rem]', 'md' => 'md:h-[8.875rem]'],
        ],
        'compact' => [
            'unscrolled' => ['mobile' => 'h-[4rem]', 'md' => 'md:h-[4.5rem]'],
            'scrolled' => ['mobile' => 'h-[3.5rem]', 'md' => 'md:h-[4rem]'],
        ],
        'standard' => [
            'unscrolled' => ['mobile' => 'h-[5.375rem]', 'md' => 'md:h-[8rem]'],
            'scrolled' => ['mobile' => 'h-[4.95rem]', 'md' => 'md:h-[7.3rem]'],
        ],
    ];

    public const HEADER_HEIGHTS = [
        'large' => [
            'unscrolled' => ['mobile' => 'h-[9.375rem]', 'md' => 'md:h-[10.9375rem]'],
            'scrolled' => ['mobile' => 'h-[8.4375rem]', 'md' => 'md:h-[9.84375rem]'],
        ],
        'compact' => [
            'unscrolled' => ['mobile' => 'h-[5rem]', 'md' => 'md:h-[5.75rem]'],
            'scrolled' => ['mobile' => 'h-[4.25rem]', 'md' => 'md:h-[4.75rem]'],
        ],
        'standard' => [
            'unscrolled' => ['mobile' => 'h-[7.5rem]', 'md' => 'md:h-[8.75rem]'],
            'scrolled' => ['mobile' => 'h-[6.75rem]', 'md' => 'md:h-[7.875rem]'],
        ],
    ];

    /**
     * Per-request overlayCapable memo, keyed on page id.
     *
     * @var array<string, bool>
     */
    private static array $overlayCapableMemo = [];

    private static mixed $overlayCapableMemoScope = null;

    public function __construct(private HeroSceneService $scenes) {}

    public static function logoSizeKey(Site $site): string
    {
        return self::resolveLogoSizeKey($site, $site->logo_size);
    }

    /**
     * Size key for the overlay (floating) logo. Null overlay_logo_size
     * inherits logo_size — including the saas_platform compact heuristic.
     */
    public static function overlayLogoSizeKey(Site $site): string
    {
        return self::resolveLogoSizeKey($site, $site->overlay_logo_size ?? $site->logo_size);
    }

    private static function resolveLogoSizeKey(Site $site, ?LogoSize $logoSize): string
    {
        $logoSize ??= LogoSize::Standard;

        if ($logoSize === LogoSize::Compact) {
            return 'compact';
        }

        if ($logoSize === LogoSize::Large) {
            return 'large';
        }

        $archetype = $site->businessProfile?->profile_data['archetype'] ?? null;

        return $archetype === 'saas_platform' ? 'compact' : 'standard';
    }

    /**
     * Unscrolled md header height from the shared logo-size matrix.
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function overlayHeaderHeight(Site $site, bool $floatingLogo = false, array $tokens = []): string
    {
        $key = $floatingLogo ? self::overlayLogoSizeKey($site) : self::logoSizeKey($site);
        $rem = self::remFromHeightClass(self::heightMatrix($site)[$key]['unscrolled']['md']);
        $pad = self::headerPaddingPx($site, $tokens);

        return $pad > 0 ? "calc({$rem} + ".($pad * 2).'px)' : $rem;
    }

    /**
     * Unscrolled mobile header height from the same matrix row as overlayHeaderHeight().
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function overlayHeaderHeightMobile(Site $site, bool $floatingLogo = false, array $tokens = []): string
    {
        $key = $floatingLogo ? self::overlayLogoSizeKey($site) : self::logoSizeKey($site);
        $rem = self::remFromHeightClass(self::heightMatrix($site)[$key]['unscrolled']['mobile']);
        $pad = self::headerPaddingPx($site, $tokens);

        return $pad > 0 ? "calc({$rem} + ".($pad * 2).'px)' : $rem;
    }

    /**
     * @return array{unscrolled: string, scrolled: string}
     */
    public static function headerHeightClasses(Site $site, bool $floatingLogo = false): array
    {
        // While the floating (overlay) logo is showing, the bar is sized to
        // THAT logo; once solid the selected logo's row applies.
        $matrix = self::heightMatrix($site);
        $row = $matrix[self::logoSizeKey($site)];
        $unscrolled = $floatingLogo ? $matrix[self::overlayLogoSizeKey($site)] : $row;
        // header_shrink off: the solid bar keeps the main logo's full-size row.
        $scrolled = \App\Support\ChromeKnobs::headerShrink($site) === 'off' ? $row['unscrolled'] : $row['scrolled'];

        return [
            'unscrolled' => $unscrolled['unscrolled']['mobile'].' '.$unscrolled['unscrolled']['md'],
            'scrolled' => $scrolled['mobile'].' '.$scrolled['md'],
        ];
    }

    /**
     * Height of the SOLID (scrolled) bar at md+, for anchors that must clear
     * the fixed header after a smooth scroll (the #enquire CTA target).
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function scrolledHeaderHeight(Site $site, array $tokens = []): string
    {
        $rem = self::remFromHeightClass(self::heightMatrix($site)[self::logoSizeKey($site)]['scrolled']['md']);
        if (\App\Support\ChromeKnobs::headerShrink($site) === 'off') {
            $rem = self::remFromHeightClass(self::heightMatrix($site)[self::logoSizeKey($site)]['unscrolled']['md']);
        }
        $pad = self::headerPaddingPx($site, $tokens);

        return $pad > 0 ? "calc({$rem} + ".($pad * 2).'px)' : $rem;
    }

    /**
     * Extra vertical room in the bar (sites.header_padding, px, 0–24).
     * An explicit header_padding knob wins; otherwise grand display_scale
     * contributes one 8px step from chrome_padding_y.
     *
     * @param  array<string, mixed>  $tokens
     */
    public static function headerPaddingPx(Site $site, array $tokens = []): int
    {
        $explicit = max(0, min(24, (int) ($site->header_padding ?? 0)));
        if ($explicit > 0) {
            return $explicit;
        }

        return ($tokens['chrome_padding_y'] ?? '') === '0.5rem' ? 8 : 0;
    }

    /**
     * @return array<string, array{unscrolled: array{mobile: string, md: string}, scrolled: array{mobile: string, md: string}}>
     */
    public static function heightMatrix(Site $site): array
    {
        return \App\Support\ChromeKnobs::headerFit($site) === 'tight' ? self::HEADER_HEIGHTS_TIGHT : self::HEADER_HEIGHTS;
    }

    private static function remFromHeightClass(string $class): string
    {
        if (preg_match('/h-\[([0-9.]+rem)\]/', $class, $match) !== 1) {
            return $class;
        }

        return $match[1];
    }

    /**
     * Whether this page can host an overlay header (first visible section
     * is a photographic hero). Stored-scene fallback is home-only.
     * page.blade.php skips this call when header_mode is not overlay.
     *
     * $leadFormAllowedHere / $contactFormRendered must match the gates
     * page.blade.php uses before it would @continue those sections.
     *
     * @param  array<int, mixed>  $sections
     * @param  array<string, mixed>  $heroImages
     */
    public function overlayCapable(
        Site $site,
        ?GeneratedPage $page,
        array $sections,
        array $heroImages,
        bool $leadFormAllowedHere = false,
        bool $contactFormRendered = false,
    ): bool {
        $memoKey = $page?->getKey();
        $scope = spl_object_id(app());
        if (self::$overlayCapableMemoScope !== $scope) {
            self::$overlayCapableMemoScope = $scope;
            self::$overlayCapableMemo = [];
        }
        if ($memoKey !== null && array_key_exists((string) $memoKey, self::$overlayCapableMemo)) {
            return self::$overlayCapableMemo[(string) $memoKey];
        }

        $result = $this->computeOverlayCapable(
            $site,
            $page,
            $sections,
            $heroImages,
            $leadFormAllowedHere,
            $contactFormRendered,
        );

        if ($memoKey !== null) {
            self::$overlayCapableMemo[(string) $memoKey] = $result;
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $sections
     * @param  array<string, mixed>  $heroImages
     */
    private function computeOverlayCapable(
        Site $site,
        ?GeneratedPage $page,
        array $sections,
        array $heroImages,
        bool $leadFormAllowedHere,
        bool $contactFormRendered,
    ): bool {
        $first = null;
        foreach ($sections as $s) {
            if (! is_array($s)) {
                continue;
            }
            $type = $s['type'] ?? '';
            if ($type === '__anchor') {
                continue;
            }
            if ($type === 'lead_form' && ! $leadFormAllowedHere) {
                continue;
            }
            if ($type === 'contact_form' && ! $contactFormRendered) {
                continue;
            }
            $first = $s;
            break;
        }

        $firstType = $first['type'] ?? null;
        if (! in_array($firstType, ['hero', 'projects_hero', 'project_detail_hero'], true)) {
            return false;
        }
        if ($firstType === 'projects_hero' && ($first['hero_enabled'] ?? true) === false) {
            return false;
        }
        if ($firstType === 'project_detail_hero') {
            // Image-backed detail heroes take the same glass overlay as the
            // other inner-page heroes; an imageless band stays a colour slab.
            return ! empty($first['hero_image_id']);
        }
        if ($firstType === 'hero' && in_array($first['variant'] ?? null, ['boxed-left', 'panel-left'], true)) {
            return false;
        }
        if (! empty($first['placeholder'])) {
            return false;
        }

        $heroKey = $first['__page_type'] ?? $page?->page_type ?? 'home';
        $hero = $heroImages[$heroKey] ?? null;
        $heroUrl = is_array($hero) ? ($hero['url'] ?? null) : (is_string($hero) ? $hero : null);
        if (! empty($heroUrl) || ! empty($first['background_image'])) {
            return true;
        }

        // Inner pages have no scenes.
        if (($page?->page_type ?? null) !== 'home') {
            return false;
        }

        // Video-only (no poster/image) is a colour slab — not capable. Skip
        // HeroSceneService::resolve unless a stored scene exists so we do not
        // hit S3 for the video arm.
        $stored = $site->home_hero_scene;
        if (! is_array($stored) || empty($stored['slides'])) {
            return false;
        }

        $scene = $this->scenes->resolve($site, ['heading' => $first['title'] ?? null]);

        return is_array($scene) && ! ($scene['is_legacy'] ?? false) && ! empty($scene['slides']);
    }
}
