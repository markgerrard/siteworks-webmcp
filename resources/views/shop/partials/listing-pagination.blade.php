@php
    $listing = is_array($listing ?? null) ? $listing : null;
    $listingPath = $listingPath ?? url()->current();
    $state = is_array($listing['state'] ?? null) ? $listing['state'] : [];
    $page = (int) ($listing['page'] ?? 1);
    $lastPage = (int) ($listing['lastPage'] ?? 1);
    $listingUrl = fn (int $target): string => \App\Support\Shop\ShopListingQuery::url($listingPath, $state, ['page' => $target]);
@endphp
@if ($listing !== null && $lastPage > 1)
<nav class="mt-8 flex flex-wrap items-center justify-center gap-2" aria-label="Pagination">
    <a href="{{ $listingUrl(1) }}" class="inline-flex items-center px-3 text-sm" style="min-height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); {{ $page === 1 ? 'pointer-events: none; opacity: 0.5;' : '' }}">First</a>
    <a href="{{ $listingUrl(max(1, $page - 1)) }}" rel="prev" class="inline-flex items-center px-3 text-sm" style="min-height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); {{ $page === 1 ? 'pointer-events: none; opacity: 0.5;' : '' }}">Prev</a>
    @for ($n = 1; $n <= $lastPage; $n++)
        <a
            href="{{ $listingUrl($n) }}"
            class="inline-flex items-center justify-center px-3 text-sm"
            style="min-height: 44px; min-width: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); {{ $n === $page ? 'background-color: var(--color-primary); color: var(--color-text-on-primary);' : '' }}"
            @if ($n === $page) aria-current="page" @endif
        >{{ $n }}</a>
    @endfor
    <a href="{{ $listingUrl(min($lastPage, $page + 1)) }}" rel="next" class="inline-flex items-center px-3 text-sm" style="min-height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); {{ $page === $lastPage ? 'pointer-events: none; opacity: 0.5;' : '' }}">Next</a>
    <a href="{{ $listingUrl($lastPage) }}" class="inline-flex items-center px-3 text-sm" style="min-height: 44px; border: 1px solid var(--color-border); border-radius: var(--radius-button); {{ $page === $lastPage ? 'pointer-events: none; opacity: 0.5;' : '' }}">Last</a>
</nav>
@endif
