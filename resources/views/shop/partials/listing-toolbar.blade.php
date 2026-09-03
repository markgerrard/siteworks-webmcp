@php
    $listing = is_array($listing ?? null) ? $listing : null;
    $showFilter = (bool) ($showFilter ?? false);
    $listingPath = $listingPath ?? url()->current();
    $state = is_array($listing) && is_array($listing['state'] ?? null) ? $listing['state'] : ['cat' => [], 'price' => [], 'avail' => [], 'opt' => [], 'attrs' => []];
    $sort = is_array($listing) ? ($listing['sort'] ?? 'featured') : 'featured';
    $sortLabels = is_array($listing) && is_array($listing['sortLabels'] ?? null)
        ? $listing['sortLabels']
        : \App\Services\Shop\CatalogueListing::sortLabels(false);
    $sortLabel = $sortLabels[$sort] ?? 'Featured';
    $filtered = is_array($listing) ? (int) ($listing['filtered'] ?? 0) : 0;
    $from = is_array($listing) ? (int) ($listing['from'] ?? 0) : 0;
    $to = is_array($listing) ? (int) ($listing['to'] ?? 0) : 0;
    $pageItems = $from > 0 ? $to - $from + 1 : 0;
    $active = is_array($listing) ? (int) ($listing['activeFilterCount'] ?? 0) : 0;
    $listingUrl = fn (array $overrides = []): string => \App\Support\Shop\ShopListingQuery::url($listingPath, $state, $overrides);
@endphp
@if ($listing !== null)
<div id="shop-listing-toolbar" class="mt-4 flex flex-wrap md:flex-nowrap items-center justify-between gap-x-4 gap-y-2">
    <p class="inline-flex items-center text-lg font-semibold m-0" style="color: var(--color-text); font-family: var(--font-display); min-height: 44px;" aria-live="polite">{{ $active > 0 && $filtered > $pageItems ? "Showing {$pageItems} of {$filtered} items" : "Showing {$pageItems} items" }}</p>

    <div class="flex w-full md:w-auto flex-wrap md:flex-nowrap items-center gap-3" style="color: var(--color-text-muted);">
        {{-- Mobile choice: native <select> below md (and as the no-JS fallback). Desktop uses the accessible menu. --}}
        <div x-data="shopSortMenu()" class="relative">
            <form method="get" action="{{ $listingPath }}" :hidden="isDesktop">
                @foreach (['cat', 'price', 'avail', 'opt'] as $field)
                    @foreach ($state[$field] ?? [] as $value)
                        <input type="hidden" name="{{ $field }}[]" value="{{ $value }}">
                    @endforeach
                @endforeach
                @foreach ($state['attrs'] ?? [] as $group => $values)
                    @foreach ($values as $value)
                        <input type="hidden" name="attr[{{ $group }}][]" value="{{ $value }}">
                    @endforeach
                @endforeach
                <label class="inline-flex items-center gap-2 text-sm m-0" style="color: var(--color-text-muted);">
                    <span>Sort by:</span>
                    <select
                        name="sort"
                        class="text-sm"
                        style="color: var(--color-text-muted); background-color: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-button); min-height: 44px; padding: 0 0.75rem;"
                        @change="$event.target.form.submit()"
                    >
                        @foreach ($sortLabels as $value => $label)
                            <option value="{{ $value }}" @selected($value === $sort)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript>
                    <button type="submit" class="text-sm underline" style="min-height: 44px;">Sort</button>
                </noscript>
            </form>

            <div class="hidden md:block" x-cloak x-show="isDesktop">
                <button
                    type="button"
                    id="shop-sort-button"
                    class="inline-flex items-center gap-1 text-sm"
                    style="background: transparent; color: var(--color-text-muted); min-height: 44px; outline-color: var(--color-accent);"
                    aria-haspopup="menu"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-controls="shop-sort-menu"
                    @click="toggle()"
                    @keydown.down.prevent="openAt(0)"
                    @keydown.up.prevent="openAt(-1)"
                    @keydown.enter.prevent="toggle()"
                    @keydown.space.prevent="toggle()"
                >
                    Sort by: <span class="font-medium">{{ $sortLabel }}</span>
                    <span aria-hidden="true">⌄</span>
                </button>
                <ul
                    id="shop-sort-menu"
                    x-ref="menu"
                    x-show="open"
                    x-cloak
                    role="menu"
                    aria-labelledby="shop-sort-button"
                    @click.outside="close()"
                    @keydown.escape.window="close()"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    x-on:keydown.home.prevent="focusAt(0)"
                    x-on:keydown.end.prevent="focusAt(-1)"
                    class="absolute right-0 mt-1 m-0 p-1 list-none"
                    style="min-width: 16rem; background-color: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: min(var(--radius-card), 1rem); z-index: 40; box-shadow: 0 8px 24px rgba(0,0,0,0.12);"
                >
                    @foreach ($sortLabels as $value => $label)
                        <li role="none">
                            <a
                                role="menuitem"
                                href="{{ $listingUrl(['sort' => $value]) }}"
                                class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                style="color: var(--color-text); text-decoration: none; min-height: 44px;"
                                @if ($value === $sort) aria-current="true" @endif
                            >
                                <span>{{ $label }}</span>
                                @if ($value === $sort)
                                    <span aria-hidden="true">✓</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        @if ($showFilter)
            <button
                type="button"
                id="shop-filter-button"
                x-data
                class="inline-flex items-center gap-2 text-sm"
                style="background: transparent; color: var(--color-text-muted); min-height: 44px; outline-color: var(--color-accent);"
                aria-haspopup="dialog"
                aria-controls="shop-filters-drawer"
                @click="show($event.currentTarget)"
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M4 6h16M7 12h10M10 18h4"/>
                </svg>
                @if ($active > 0)
                    Filter ({{ $active }})
                @else
                    Filter
                @endif
            </button>
        @endif
    </div>
</div>
@php
    $pills = \App\Support\Shop\ShopListingQuery::pills(
        \App\Services\Shop\CatalogueListing::drawerGroups($listing['facets'] ?? [], $state),
        $state,
        $listingPath,
    );
@endphp
@if ($pills !== [])
    <nav class="mt-3 flex flex-wrap items-center gap-2" aria-label="Active filters">
        @foreach ($pills as $pill)
            <a
                href="{{ $pill['href'] }}"
                class="inline-flex items-center gap-1 px-3 py-1.5 text-sm"
                style="border: 1px solid var(--color-border); border-radius: 9999px; color: var(--color-text); background-color: var(--color-surface); min-height: 44px;"
            >{{ $pill['label'] }} <span aria-hidden="true">×</span></a>
        @endforeach
    </nav>
@endif
@once
<script>
{!! preg_replace('/^export /m', '', (string) file_get_contents(resource_path('js/shop/sort-menu.js'))) !!}
</script>
@endonce
@endif
