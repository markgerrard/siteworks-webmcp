<?php

namespace App\Services\Site;

class AccentWordRenderer
{
    /**
     * Wrap emphasised slices of $title with a span coloured via --color-accent.
     *
     * When $accentRanges is a valid list of codepoint {start, length} ranges
     * into the stored, unescaped title, each raw slice is escaped individually
     * then wrapped. Invalid or absent ranges fall through to the original
     * first-match $accentWord behaviour, which is byte-identical when ranges
     * are not stored.
     *
     * Returns HTML-safe output. Callers render with `{!! !!}`.
     *
     * $style = 'italic' adds font-style: italic on the span. Null (and any
     * other value) keeps today's colour-only markup byte-identical.
     *
     * @param  list<array{start: int, length: int}>|null  $accentRanges
     */
    public function wrap(string $title, ?string $accentWord, ?string $style = null, ?array $accentRanges = null): string
    {
        $ranges = $this->usableRanges($accentRanges, $title);
        if ($ranges !== []) {
            return $this->wrapRanges($title, $ranges, $style);
        }

        $escapedTitle = e($title);

        if (! is_string($accentWord) || trim($accentWord) === '') {
            return $escapedTitle;
        }

        $escapedAccent = e($accentWord);

        if (mb_stripos($escapedTitle, $escapedAccent) === false) {
            return $escapedTitle;
        }

        $pattern = '/'.preg_quote($escapedAccent, '/').'/i';

        return preg_replace_callback(
            $pattern,
            fn (array $m): string => $this->accentSpan($m[0], $style),
            $escapedTitle,
            limit: 1,
        );
    }

    /**
     * @param  list<array{start: int, length: int}>  $ranges
     */
    private function wrapRanges(string $title, array $ranges, ?string $style): string
    {
        $html = '';
        $cursor = 0;

        foreach ($ranges as $range) {
            $start = $range['start'];
            $length = $range['length'];
            if ($start > $cursor) {
                $html .= e(mb_substr($title, $cursor, $start - $cursor));
            }
            $html .= $this->accentSpan(e(mb_substr($title, $start, $length)), $style);
            $cursor = $start + $length;
        }

        $remainder = mb_substr($title, $cursor);
        if ($remainder !== '') {
            $html .= e($remainder);
        }

        return $html;
    }

    /**
     * @param  list<array{start?: mixed, length?: mixed}>|null  $ranges
     * @return list<array{start: int, length: int}>
     */
    private function usableRanges(?array $ranges, string $title): array
    {
        if ($ranges === null || $ranges === [] || ! array_is_list($ranges)) {
            return [];
        }

        $length = mb_strlen($title);
        $cursor = 0;
        $usable = [];

        foreach ($ranges as $range) {
            if (! is_array($range)) {
                return [];
            }

            $start = $range['start'] ?? null;
            $span = $range['length'] ?? null;
            if (! is_int($start) || ! is_int($span) || $start < 0 || $span < 1) {
                return [];
            }
            if ($start < $cursor || ($start + $span) > $length) {
                return [];
            }

            $usable[] = ['start' => $start, 'length' => $span];
            $cursor = $start + $span;
        }

        return $usable;
    }

    private function accentSpan(string $escapedInner, ?string $style): string
    {
        $styleAttr = 'color: var(--color-accent);'.($style === 'italic' ? ' font-style: italic;' : '');
        // Glue only 2-word accent phrases ("Civil Works", "Plant Hire") so
        // the browser treats them as one unbreakable token when text-balance
        // optimises line widths. 3+-word phrases are allowed to break.
        $wordCount = count(preg_split('/\s+/', trim($escapedInner)) ?: []);
        $inner = $wordCount === 2
            ? preg_replace('/\s+/', '&nbsp;', $escapedInner)
            : $escapedInner;

        return '<span class="accent-word" style="'.$styleAttr.'">'.$inner.'</span>';
    }
}
