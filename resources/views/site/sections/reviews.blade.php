@php
    $cache = $site->reviews_cache ?? null;
    // Cache is authoritative (matches hero trust pill); section.provider is
    // a fallback for composer-stamped google defaults / missing cache key.
    $reviewsProvider = $cache['provider'] ?? $section['provider'] ?? 'google';
    $isCheckatrade = $reviewsProvider === 'checkatrade';
    $ratingScale = (int) ($cache['rating_scale'] ?? 5);
    if ($ratingScale < 1) {
        $ratingScale = 5;
    }
    $providerLabel = $isCheckatrade ? 'Checkatrade' : 'Google';
    $style = $section['display_style'] ?? 'carousel';
    $items = array_slice($cache['reviews'] ?? [], 0, $section['max_items'] ?? 5);
    $containerClass = match ($style) {
        'grid' => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6',
        'stacked' => 'flex flex-col gap-6',
        default => 'flex gap-6 overflow-x-auto snap-x snap-mandatory pb-4',
    };
    $ratingFormatted = isset($cache['rating']) ? number_format((float) $cache['rating'], 1) : null;
    $ratingDisplay = $ratingFormatted === null
        ? null
        : ($ratingScale === 10 ? $ratingFormatted.'/10' : $ratingFormatted);
    $summaryStars = isset($cache['rating'])
        ? (int) round(((float) $cache['rating'] / $ratingScale) * 5)
        : 0;
@endphp

@if (! empty($cache) && ! empty($items))
<section class="site-section-spacing"@if ($isCheckatrade) data-reviews-provider="checkatrade"@endif>
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        {{-- Header: rating + count --}}
        @if (! empty($section['show_rating']))
            <div class="text-center mb-8">
                <div class="flex items-center justify-center gap-3">
                    <span class="text-4xl font-extrabold" style="color: var(--brand-primary);">{{ $ratingDisplay }}</span>
                    <div class="flex gap-0.5" aria-hidden="true">
                        @for ($i = 1; $i <= 5; $i++)
                            <svg class="w-6 h-6" viewBox="0 0 20 20" fill="{{ $i <= $summaryStars ? 'gold' : '#cbd5e1' }}">
                                <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                            </svg>
                        @endfor
                    </div>
                </div>
                @if (! empty($section['show_review_count']))
                    <div class="text-sm opacity-75 mt-2">Based on {{ (int) $cache['user_ratings_total'] }} {{ $providerLabel }} reviews</div>
                @endif
            </div>
        @endif

        {{-- Cards --}}
        <div class="{{ $containerClass }}">
            @foreach ($items as $review)
                @php
                    $rawReviewRating = (float) ($review['rating'] ?? 0);
                    $reviewStars = $ratingScale === 5
                        ? (int) $rawReviewRating
                        : (int) round(($rawReviewRating / $ratingScale) * 5);
                @endphp
                <article class="bg-white rounded-lg p-6 shadow-sm {{ $style === 'carousel' ? 'snap-start flex-shrink-0 w-80 md:w-96' : '' }}">
                    <header class="flex items-center gap-3 mb-3">
                        @if (! empty($review['profile_photo_url']))
                            <img src="{{ $review['profile_photo_url'] }}" alt="" class="w-10 h-10 rounded-full">
                        @else
                            <div class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 font-bold">
                                {{ strtoupper(substr($review['author_name'] ?? '?', 0, 1)) }}
                            </div>
                        @endif
                        <div class="flex-1">
                            <div class="font-semibold">{{ $review['author_name'] ?? '' }}</div>
                            <div class="flex gap-0.5 mt-0.5" aria-hidden="true">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="{{ $i <= $reviewStars ? 'gold' : '#cbd5e1' }}">
                                        <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                                    </svg>
                                @endfor
                            </div>
                        </div>
                        <span class="text-xs opacity-60">{{ $review['relative_time_description'] ?? '' }}</span>
                    </header>
                    <p class="text-sm leading-relaxed">
                        {{ \Illuminate\Support\Str::limit($review['text'] ?? '', 280) }}
                        @if (mb_strlen($review['text'] ?? '') > 280)
                            <a href="{{ $cache['url'] ?? '#' }}" target="_blank" rel="noopener" class="font-semibold hover:underline" style="color: var(--brand-primary);">Read more ↗</a>
                        @endif
                    </p>
                </article>
            @endforeach
        </div>

        {{-- Footer --}}
        <div class="text-center mt-8 flex items-center justify-center gap-4">
            <a href="{{ $cache['url'] ?? '#' }}" target="_blank" rel="noopener" class="font-semibold hover:underline" style="color: var(--brand-primary);">
                Read all reviews on {{ $providerLabel }} ↗
            </a>
            @if (! empty($section['show_attribution']))
                <span class="text-xs opacity-60">Powered by {{ $providerLabel }}</span>
            @endif
        </div>
    </div>
</section>
@endif
