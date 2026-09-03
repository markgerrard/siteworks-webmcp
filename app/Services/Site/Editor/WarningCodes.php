<?php

namespace App\Services\Site\Editor;

use InvalidArgumentException;

final class WarningCodes
{
    /**
     * @var list<string>
     */
    private const CORE_CODES = [
        'contrast_below_aa',
        'contrast_below_aaa',
        'meta_description_long',
        'meta_title_long',
        'alt_text_missing',
        'accent_ranges_dropped',
        'variant_not_in_recipe',
        'effective_truncated',
        'async_pending',
        'preview_stale',
        'video_mode_conflict',
        'scene_active',
        'asset_unreferenced',
        'spend_near_cap',
    ];

    /**
     * @var list<string>
     */
    private static array $codes = [];

    private static ?string $version = null;

    /**
     * Register a warning code at boot time.
     */
    public static function register(string $code): void
    {
        if (in_array($code, self::$codes, true)) {
            return;
        }

        $wasSealed = self::$version !== null;
        self::$codes[] = $code;

        if ($wasSealed) {
            self::seal();
        }
    }

    public static function registerDefaults(): void
    {
        foreach (self::CORE_CODES as $code) {
            self::register($code);
        }
    }

    /**
     * Seal the registry and compute a version stamp.
     */
    public static function seal(): void
    {
        sort(self::$codes);
        self::$version = sha1(implode(',', self::$codes));
    }

    /**
     * Reset the registry for testing.
     */
    public static function reset(): void
    {
        self::$codes = [];
        self::$version = null;
    }

    /**
     * Assert that a code has been registered.
     *
     * @throws InvalidArgumentException when the code is not registered.
     */
    public static function assert(string $code): void
    {
        if (! in_array($code, self::$codes, true)) {
            throw new InvalidArgumentException("Unknown warning code [{$code}].");
        }
    }

    /**
     * The version stamp for the current set of registered codes.
     */
    public static function version(): string
    {
        if (self::$version === null) {
            return 'unsealed';
        }

        return self::$version;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::$codes;
    }
}
