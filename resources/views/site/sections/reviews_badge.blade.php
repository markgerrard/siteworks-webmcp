@php
    $cache = $site->reviews_cache ?? null;
    // Cache is authoritative (matches hero trust pill); section.provider is
    // a fallback for composer-stamped google defaults / missing cache key.
    $reviewsProvider = $cache['provider'] ?? ($section ?? [])['provider'] ?? 'google';
    $isCheckatrade = $reviewsProvider === 'checkatrade';
@endphp

@if (($site->reviews_hero_badge_mode ?? \App\Enums\ReviewsHeroBadgeMode::On)->allowsPage($pageType ?? 'home') && ! empty($cache) && (int) ($cache['user_ratings_total'] ?? 0) > 0)
    @php
        $ratingScale = (int) ($cache['rating_scale'] ?? 5);
        if ($ratingScale < 1) {
            $ratingScale = 5;
        }
        $providerLabel = $isCheckatrade ? 'Checkatrade' : 'Google';
        $rating = number_format((float) $cache['rating'], 1);
        $ratingDisplay = $ratingScale === 10 ? $rating.'/10' : $rating;
        $summaryStars = (int) round(((float) $cache['rating'] / $ratingScale) * 5);
        $total = (int) $cache['user_ratings_total'];
        $countLabel = $total >= 90 ? floor($total / 10) * 10 . '+' : $total;
        $countText = $isCheckatrade
            ? $countLabel.' '.$providerLabel.' reviews'
            : $countLabel.' '.$providerLabel.' Reviews';
        $url = $cache['url'] ?? '';
    @endphp
    {{-- Mobile-only — desktop renders an inline trust pill inside hero
         above the eyebrow. md:hidden so the strip only shows below md. --}}
    <div class="py-4 md:hidden" style="background-color: var(--color-surface-alt);"@if ($isCheckatrade) data-reviews-provider="checkatrade"@endif>
        <div class="site-shell-container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-sm md:text-base">
                @if ($isCheckatrade)
                {{-- Tick-in-shield mark for Checkatrade attribution --}}
                <svg class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M12 3l7 3v5.5c0 4.5-3 8.5-7 9.5-4-1-7-5-7-9.5V6l7-3z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                @else
                {{-- Google "G" multicolour mark — instant third-party credibility --}}
                <svg class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                @endif

                <span class="flex gap-0.5" aria-hidden="true">
                    @for ($s = 1; $s <= 5; $s++)
                        <svg class="w-4 h-4 md:w-[18px] md:h-[18px]" viewBox="0 0 20 20"
                             fill="{{ $s <= $summaryStars ? '#fbbf24' : '#d1d5db' }}">
                            <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                        </svg>
                    @endfor
                </span>

                <span class="font-bold" style="color: var(--color-text-on-alt);">{{ $ratingDisplay }} Rating</span>

                <span aria-hidden="true" class="opacity-30" style="color: var(--color-text-on-alt);">|</span>

                <span class="font-medium" style="color: var(--color-text-on-alt);">
                    @if ($isCheckatrade)
                        {{-- Wordmark only, matching the hero pill treatment.
                             Light surface strip — primary (navy/red) mark. --}}
                        @include('site.partials._checkatrade-logo', ['reversed' => false, 'height' => '1.4em'])
                    @else
                        {{ $countText }}
                    @endif
                </span>
            </div>
        </div>
    </div>
@endif
