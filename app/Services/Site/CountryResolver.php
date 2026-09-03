<?php

namespace App\Services\Site;

use App\Models\Site;

/**
 * Deterministic country resolver. Replaces the earlier "ask the model to
 * guess from {location} and audience text" pattern with rule-based
 * resolution so an ambiguous location ("Perth" / "Cambridge" / "Newcastle")
 * doesn't end up rendered with the wrong country's housing stock /
 * landscape / signage.
 *
 * Resolution order:
 *   1. site.country if set (admin or onboarding-time override)
 *   2. International dialing code on the first contact phone (+44, +61,
 *      +64, +353)
 *   3. Australian / NZ state-or-region tokens in the location string
 *      (TAS, NSW, VIC, QLD, SA, WA, NT, ACT, Tasmania, Auckland, etc.)
 *   4. UK region tokens (Scotland, England, Wales, etc.)
 *   5. Audience field tokens
 *   6. Fall back to UK (the default trade we serve, matches earlier
 *      hardcoded behaviour)
 *
 * Returns the prompt-friendly label, not a strict ISO code, because
 * downstream prompts use it verbatim ("Australian context", "UK
 * housing stock", etc.).
 */
class CountryResolver
{
    public const UK = 'UK';
    public const AU = 'Australia';
    public const NZ = 'New Zealand';
    public const IE = 'Ireland';

    private const AU_TOKENS = [
        'tasmania', 'tas', 'new south wales', 'nsw', 'victoria', 'vic',
        'queensland', 'qld', 'south australia', 'northern territory', 'nt',
        'australian capital territory', 'act', 'western australia',
        'australia', 'launceston', 'hobart', 'sydney', 'melbourne',
        'brisbane', 'perth', 'adelaide', 'darwin', 'canberra',
    ];

    private const NZ_TOKENS = [
        'new zealand', 'auckland', 'wellington', 'christchurch',
        'hamilton', 'tauranga', 'dunedin', 'north island', 'south island',
    ];

    // Republic of Ireland only. Northern Ireland (Belfast, Derry,
    // Armagh) sits in UK_TOKENS — it's part of the United Kingdom.
    private const IE_TOKENS = [
        'ireland', 'dublin', 'cork', 'galway', 'limerick',
        'waterford', 'leinster', 'munster', 'connacht',
    ];

    private const UK_TOKENS = [
        'united kingdom', 'england', 'scotland', 'wales', 'cornwall',
        'yorkshire', 'lancashire', 'kent', 'essex', 'london',
        'manchester', 'edinburgh', 'cardiff', 'bristol', 'liverpool',
        'glasgow', 'birmingham', 'leeds', 'sheffield', 'newcastle upon tyne',
        'penzance', 'wigan',
        // Northern Ireland — part of the UK, not the Republic of Ireland.
        'northern ireland', 'belfast', 'derry', 'londonderry', 'armagh',
    ];

    public function resolveLabel(Site $site): string
    {
        // 1. Explicit override on the model (when the column exists).
        if (! empty($site->country) && is_string($site->country)) {
            $explicit = $this->normaliseExplicit($site->country);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        $profile = $site->businessProfile?->profile_data ?? [];

        // 2. International dialing code on the first phone.
        $phone = $profile['contact']['phones'][0] ?? null;
        if (is_string($phone)) {
            $compact = preg_replace('/[^0-9+]/', '', $phone);
            if (str_starts_with((string) $compact, '+44') || str_starts_with((string) $compact, '0044')) {
                return self::UK;
            }
            if (str_starts_with((string) $compact, '+61') || str_starts_with((string) $compact, '0061')) {
                return self::AU;
            }
            if (str_starts_with((string) $compact, '+64') || str_starts_with((string) $compact, '0064')) {
                return self::NZ;
            }
            if (str_starts_with((string) $compact, '+353') || str_starts_with((string) $compact, '00353')) {
                return self::IE;
            }
        }

        // 3. Strong signals first — audience + geo fields. These are
        // less ambiguous than `location` (which often holds bare
        // ambiguous town names like "Perth" or "Newcastle"). If the
        // audience names a country/state, trust it.
        $strongBlob = mb_strtolower(implode(' ', array_filter([
            (string) ($profile['audience'] ?? ''),
            (string) ($profile['geo']['country'] ?? ''),
            (string) ($profile['geo']['region'] ?? ''),
        ])));

        if ($strongBlob !== '') {
            if ($this->matchesAny($strongBlob, self::AU_TOKENS)) {
                return self::AU;
            }
            if ($this->matchesAny($strongBlob, self::NZ_TOKENS)) {
                return self::NZ;
            }
            if ($this->matchesAny($strongBlob, self::IE_TOKENS)) {
                return self::IE;
            }
            if ($this->matchesAny($strongBlob, self::UK_TOKENS)) {
                return self::UK;
            }
        }

        // 4. Weak signal — location only. Hits common ambiguous
        // tokens (Perth, Newcastle, Cambridge), so check UK first:
        // UK is the platform's primary market and an explicit UK
        // token in the location string ("Perth, Scotland",
        // "Newcastle upon Tyne") should beat the bare-name AU/NZ
        // matches. If no UK token matches but an AU/NZ/IE token does,
        // accept that.
        $weakBlob = mb_strtolower((string) ($site->location ?? ''));
        if ($weakBlob !== '') {
            if ($this->matchesAny($weakBlob, self::UK_TOKENS)) {
                return self::UK;
            }
            if ($this->matchesAny($weakBlob, self::IE_TOKENS)) {
                return self::IE;
            }
            if ($this->matchesAny($weakBlob, self::NZ_TOKENS)) {
                return self::NZ;
            }
            if ($this->matchesAny($weakBlob, self::AU_TOKENS)) {
                return self::AU;
            }
        }

        // 6. Default to UK — the platform's primary market today; matches
        // the bias the prompts had hardcoded before this resolver existed.
        return self::UK;
    }

    private function normaliseExplicit(string $raw): ?string
    {
        $clean = strtolower(trim($raw));

        return match ($clean) {
            'uk', 'gb', 'united kingdom', 'great britain' => self::UK,
            'au', 'australia' => self::AU,
            'nz', 'new zealand' => self::NZ,
            'ie', 'ireland' => self::IE,
            default => null,
        };
    }

    /**
     * Word-boundary token match so "Penzance" doesn't accidentally hit on
     * a substring of an unrelated longer word. Tokens are pre-lowercased.
     *
     * @param  array<int, string>  $tokens
     */
    private function matchesAny(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $pattern = '/(?<![\\p{L}])'.preg_quote($token, '/').'(?![\\p{L}])/u';
            if (preg_match($pattern, $haystack)) {
                return true;
            }
        }

        return false;
    }
}
