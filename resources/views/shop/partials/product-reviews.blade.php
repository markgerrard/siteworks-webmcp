@php
    $label = $reviewSettings->label ?? 'Reviews';
    $reviews = $productReviews;
    $distribution = is_array($reviewDistribution ?? null) ? $reviewDistribution : [];
    $total = array_sum($distribution);
    $showForm = (bool) ($reviewSettings->publicForm ?? false);
    $formHref = route('shop.product.review', $product['slug']);
@endphp
<div id="product-reviews" class="space-y-4">
    <h2 class="text-lg font-semibold">{{ $label }}</h2>

    @if ($total > 0)
        <div class="space-y-1" aria-label="Rating distribution">
            @for ($rating = 5; $rating >= 1; $rating--)
                @php
                    $count = (int) ($distribution[$rating] ?? 0);
                    $pct = $total > 0 ? round(($count / $total) * 100) : 0;
                @endphp
                <div class="flex items-center gap-2 text-sm">
                    <span class="w-8 tabular-nums">{{ $rating }}★</span>
                    <span class="h-2 flex-1 overflow-hidden" style="background-color: var(--color-surface-alt); border-radius: var(--radius-button);">
                        <span class="block h-full" style="width: {{ $pct }}%; background-color: var(--color-accent);"></span>
                    </span>
                    <span class="w-8 text-right tabular-nums">{{ $count }}</span>
                </div>
            @endfor
        </div>
    @endif

    @if ($showForm)
        <a
            href="{{ $formHref }}"
            class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            style="display: inline-flex; align-items: center; min-height: 44px; color: var(--color-text); outline-color: var(--color-accent);"
        >Write a review</a>
    @endif

    @forelse ($reviews ?? [] as $review)
        <article class="border-t pt-4" style="border-color: var(--color-border);">
            <h3 class="font-medium">{{ $review->title }}</h3>
            <p class="text-sm" style="color: var(--color-text-muted);">{{ $review->author_name }} · {{ $review->rating }} out of 5</p>
            <p class="mt-2">{{ $review->body }}</p>
        </article>
    @empty
        <p class="text-sm" style="color: var(--color-text-muted);">No {{ mb_strtolower($label) }} yet.</p>
    @endforelse

    @if ($reviews && $reviews->hasMorePages())
        <a
            href="{{ $reviews->nextPageUrl() }}#product-reviews"
            class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            style="display: inline-flex; align-items: center; min-height: 44px; color: var(--color-text); outline-color: var(--color-accent);"
        >Show more</a>
    @endif
</div>
