{{-- Desktop-only inline hero trust pill (5.0 ★ · N Google Reviews).
     Shared by home heroes (always, when the site has cached reviews) and
     inner-page heroes (only when the page's sections include a
     reviews_badge — the injected section is the per-page opt-in; the
     badge strip itself is the md:hidden mobile counterpart).
     Expects: $heroReviewsCache, $heroReviewsTotal, $heroShowTrust,
     $heroShowCount, $textColorClass.
     Provider/scale derived from the cache (no section context on the pill). --}}
@if ($heroShowTrust)
    @php
        $pillProvider = $heroReviewsCache['provider'] ?? 'google';
        $pillScale = (int) ($heroReviewsCache['rating_scale'] ?? 5);
        if ($pillScale < 1) {
            $pillScale = 5;
        }
        $pillIsCheckatrade = $pillProvider === 'checkatrade';
        // Native cache scale: show "9.9/10" when scale is 10; bare "4.8" for /5.
        $heroRating = number_format((float) $heroReviewsCache['rating'], 1);
        $heroRatingDisplay = $pillScale === 10 ? $heroRating.'/10' : $heroRating;
        $pillStars = (int) round(((float) $heroReviewsCache['rating'] / $pillScale) * 5);
        $heroCountLabel = $heroReviewsTotal >= 90 ? floor($heroReviewsTotal / 10) * 10 . '+' : $heroReviewsTotal;
        // Google: "N Google Reviews" / "on Google"; Checkatrade: lowercase "reviews".
        $pillCountText = $pillIsCheckatrade
            ? $heroCountLabel.' Checkatrade reviews'
            : $heroCountLabel.' Google Reviews';
        $pillOnText = $pillIsCheckatrade ? 'on Checkatrade' : 'on Google';
    @endphp
    {{-- Desktop-only inline trust pill above the eyebrow.
         Mobile uses the reviews_badge strip below the hero
         instead; mobile already loses too much vertical
         space if both stack. bg-current / border-current
         + opacity adapt to whatever colour the hero text
         is so the pill survives a future light-hero
         variant without per-variant logic. --}}
    <div class="hidden md:inline-flex items-center gap-2 mb-3 px-3 py-1.5 rounded-full text-xs font-medium bg-current/10 border border-current/20 backdrop-blur-sm {{ $textColorClass }}">
        @include('site.partials._provider-mark', [
            'provider' => $pillProvider,
            'markClass' => 'w-3.5 h-3.5 flex-shrink-0',
        ])
        <span class="inline-flex gap-px" aria-hidden="true">
            @for ($s = 1; $s <= 5; $s++)
                <svg class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="{{ $s <= $pillStars ? '#fbbf24' : '#d1d5db' }}">
                    <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                </svg>
            @endfor
        </span>
        <span class="font-bold">{{ $heroRatingDisplay }}</span>
        @php
            // Wordmark reversal follows the pill's text colour — light text
            // means dark backdrop, which needs the reversed (white) navy.
            $pillLogoReversed = str_contains($textColorClass ?? 'text-white', 'white');
        @endphp
        @if ($pillIsCheckatrade)
            {{-- Checkatrade: wordmark only — no cached count (undersells the
                 live profile), no "reviews" filler. Sized up so the mark reads. --}}
            <span aria-hidden="true" class="opacity-50">·</span>
            @include('site.partials._checkatrade-logo', ['reversed' => $pillLogoReversed, 'height' => '1.5em'])
        @elseif ($heroShowCount)
            <span aria-hidden="true" class="opacity-50">·</span>
            <span class="opacity-90">{{ $pillCountText }}</span>
        @else
            <span class="opacity-90">{{ $pillOnText }}</span>
        @endif
    </div>
@endif
