<?php

namespace App\Enums;

/**
 * How the home hero background video repeats.
 *
 * Stored in sites.home_hero_video_loop (nullable string). NULL means
 * "never configured" and resolves to Continuous — the behaviour every
 * site had before this column existed, so adding it changes nothing for
 * sites nobody has touched.
 *
 * HTML5 <video loop> is a boolean: it either repeats forever or plays
 * once. A finite repeat count therefore needs a small amount of JS
 * counting `ended` events, which is what Count1/2/3 emit.
 *
 * Semantics — the number is how many times it REPEATS, so the clip is
 * played count+1 times in total:
 *   none       → plays once, holds on the final frame
 *   1 / 2 / 3  → plays 2 / 3 / 4 times, then holds on the final frame
 *   continuous → native loop attribute, repeats forever
 */
enum HeroVideoLoop: string
{
    case None = 'none';
    case Count1 = '1';
    case Count2 = '2';
    case Count3 = '3';
    case Continuous = 'continuous';

    /**
     * Resolve a stored value (possibly null) to an effective mode.
     * NULL → Continuous, preserving pre-column behaviour.
     */
    public static function resolve(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::Continuous) : self::Continuous;
    }

    /** Whether to emit the native `loop` attribute. */
    public function isNative(): bool
    {
        return $this === self::Continuous;
    }

    /**
     * Number of REPEATS after the first play, or null when the repeat
     * count is not finite (none = 0 repeats, continuous = null).
     */
    public function repeats(): ?int
    {
        return match ($this) {
            self::None => 0,
            self::Count1 => 1,
            self::Count2 => 2,
            self::Count3 => 3,
            self::Continuous => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No loop — play once',
            self::Count1 => 'Loop once (plays twice)',
            self::Count2 => 'Loop twice (plays 3 times)',
            self::Count3 => 'Loop 3 times (plays 4 times)',
            self::Continuous => 'Continuous loop',
        };
    }
}
