<?php

namespace App\Support\Shop;

final class AccentWordChips
{
    /**
     * Words available as accent-word chips: split on whitespace, punctuation
     * trimmed off each end, empties dropped, and case-insensitive duplicates
     * collapsed (first casing wins).
     *
     * @return list<string>
     */
    public static function for(string $text): array
    {
        $tokens = preg_split('/\s+/u', $text) ?: [];
        $punctuation = ".,!?&()'\"\u{201C}\u{201D}\u{2018}\u{2019}\u{2014}\u{2013}-";

        $seen = [];
        $chips = [];
        foreach ($tokens as $token) {
            $word = trim($token, $punctuation);
            if ($word === '') {
                continue;
            }
            $key = mb_strtolower($word);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $chips[] = $word;
        }

        return $chips;
    }
}
