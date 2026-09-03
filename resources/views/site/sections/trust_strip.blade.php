@php
    $editor = function (string $field, string $type) use ($pageId, $sectionIndex, $emitMarkers, $section): string {
        if (! $emitMarkers) {
            return '';
        }

        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="trust_strip" data-editable-field="'.e($field).'"';
    };
    $sources = in_array($section['sources'] ?? null, ['site', 'product', 'both'], true)
        ? $section['sources']
        : 'both';
    $layout = ($section['layout'] ?? null) === 'carousel' ? 'carousel' : 'strip';
    $heading = is_string($section['heading'] ?? null) ? $section['heading'] : 'What customers say';
    $reviewsLabel = is_string($section['reviews_label'] ?? null) && trim($section['reviews_label']) !== ''
        ? trim($section['reviews_label'])
        : 'reviews';
    $minimum = max(1, (int) ($section['min_reviews'] ?? 3));
    $summary = isset($site)
        ? app(\App\Services\Site\TrustSummary::class)->for($site, $sources)
        : ['average' => 0.0, 'count' => 0, 'reviews' => []];
    $reviews = array_slice($summary['reviews'], 0, $layout === 'carousel' ? 8 : 3);
    $external = is_array($section['external'] ?? null) ? $section['external'] : [];
    $externalUrl = \App\Services\Site\SectionSchema::isSafeLink($external['url'] ?? null)
        ? trim($external['url'])
        : null;
    $externalLabel = is_string($external['label'] ?? null) ? trim($external['label']) : '';
    $externalRating = is_numeric($external['rating'] ?? null) ? round((float) $external['rating'], 1) : null;
    $externalCount = is_numeric($external['count'] ?? null) ? max(0, (int) $external['count']) : null;
    $aria = number_format((float) $summary['average'], 1).' out of 5, '.$summary['count'].' '.$reviewsLabel;
@endphp
@if ($summary['count'] >= $minimum)
    <section class="site-section-spacing" data-trust-strip data-trust-sources="{{ $sources }}" data-trust-layout="{{ $layout }}" style="background-color: var(--color-surface-alt); border-top: 1px solid var(--color-border); border-bottom: 1px solid var(--color-border);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-8">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="flex flex-col gap-2">
                        <h2 class="text-2xl md:text-3xl font-extrabold" style="color: var(--color-text-on-alt);"{!! $editor('heading', 'plain') !!}>{{ $heading }}</h2>
                        <div class="flex flex-wrap items-center gap-3">
                            @include('shop.partials.product-rating-stars', [
                                'avg' => $summary['average'],
                                'count' => $summary['count'],
                                'aria' => $aria,
                                'showCount' => false,
                                'wrapperClass' => 'flex items-center gap-1',
                            ])
                            <span class="text-sm font-semibold" style="color: var(--color-text-muted-on-alt);">
                                {{ number_format((float) $summary['average'], 1) }} · {{ $summary['count'] }} <span{!! $editor('reviews_label', 'plain') !!}>{{ $reviewsLabel }}</span>
                            </span>
                        </div>
                    </div>
                    @if ($externalUrl !== null && $externalLabel !== '')
                        <a href="{{ $externalUrl }}" class="text-sm font-semibold underline underline-offset-4" style="color: var(--brand-accent-text-on-alt);" rel="noopener noreferrer">
                            <span{!! $editor('external.label', 'plain') !!}>{{ $externalLabel }}</span>
                            @if ($externalRating !== null && $externalRating >= 0 && $externalRating <= 5)
                                · {{ number_format($externalRating, 1) }}
                            @endif
                            @if ($externalCount !== null)
                                · {{ $externalCount }} <span{!! $editor('reviews_label', 'plain') !!}>{{ $reviewsLabel }}</span>
                            @endif
                        </a>
                    @endif
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('sources', 'enum') !!}></span>
                        <span class="hidden"{!! $editor('layout', 'enum') !!}></span>
                        <span class="hidden"{!! $editor('min_reviews', 'integer') !!}></span>
                        <span class="hidden"{!! $editor('external.url', 'url') !!}></span>
                        <span class="hidden"{!! $editor('external.rating', 'decimal') !!}></span>
                        <span class="hidden"{!! $editor('external.count', 'integer') !!}></span>
                    @endif
                </div>

                @if ($layout === 'carousel')
                    @include('site.partials.carousel', [
                        'carouselItems' => $reviews,
                        'carouselItemView' => 'site.partials.trust-review-card',
                        'carouselItemVariable' => 'review',
                        'carouselLabel' => $reviewsLabel,
                    ])
                @else
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        @foreach ($reviews as $review)
                            @include('site.partials.trust-review-card', ['review' => $review])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
@endif
