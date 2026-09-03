@php
    $facets = is_array($facets ?? null) ? $facets : [];
    $indexMode = (bool) ($indexMode ?? false);
    $hasFacets = ($facets['category'] ?? []) !== []
        || ($facets['price'] ?? []) !== []
        || ($facets['availability'] ?? []) !== []
        || ($facets['options'] ?? []) !== []
        || ($facets['attributes'] ?? []) !== [];
    $listing = is_array($listing ?? null) ? $listing : null;
    $listingPath = $listingPath ?? url()->current();
    $state = is_array($listing['state'] ?? null) ? $listing['state'] : ['sort' => 'featured', 'cat' => [], 'price' => [], 'avail' => [], 'opt' => [], 'attrs' => []];
    $groups = $hasFacets ? \App\Services\Shop\CatalogueListing::drawerGroups($facets, $state) : [];
    $clearUrl = \App\Support\Shop\ShopListingQuery::url($listingPath, $state, [
        'cat' => [],
        'price' => [],
        'avail' => [],
        'opt' => [],
        'attrs' => [],
        'sort' => $state['sort'] ?? 'featured',
    ]);
    $checkboxClass = 'inline-flex items-center gap-2 text-sm w-full';
@endphp
{{-- /shop is a curated index. This partial renders nothing on indexMode.
     Category pages: listing toolbar always; Filter drawer only when facets exist. --}}
@if (! $indexMode && $hasFacets)
<noscript></noscript>
<div
    id="shop-filters"
    x-data="shopFilters()"
    data-facets="{{ json_encode($facets) }}"
>
    @include('shop.partials.listing-toolbar', [
        'listing' => $listing,
        'listingPath' => $listingPath,
        'showFilter' => true,
    ])

    <noscript>
        <form method="get" action="{{ $listingPath }}" class="mt-4">
            @include('shop.partials.filter-groups', ['groups' => $groups, 'state' => $state, 'inDrawer' => false])
            <div class="flex flex-wrap gap-3 mt-4">
                <a href="{{ $clearUrl }}" class="inline-flex items-center px-4 py-2 text-sm" style="border: 1px solid var(--color-border); border-radius: var(--radius-button); min-height: 44px; color: var(--color-text);">Clear filters</a>
                <button type="submit" class="inline-flex items-center px-4 py-2 text-sm" style="background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); min-height: 44px;">Apply filters</button>
            </div>
        </form>
    </noscript>

    <template x-teleport="body">
        <div id="shop-filters-modal-layer" x-show="open" x-cloak>
            <div
                x-bind="backdropBind"
                @click="close()"
                class="fixed inset-0"
                style="background-color: rgba(0, 0, 0, 0.4); z-index: 60;"
                aria-hidden="true"
            ></div>

            <form
                id="shop-filters-drawer"
                method="get"
                action="{{ $listingPath }}"
                role="dialog"
                aria-modal="true"
                aria-labelledby="shop-filters-drawer-title"
                @keydown.escape.window="close()"
                @keydown.tab="trap($event)"
                class="fixed top-0 right-0 bottom-0 flex flex-col"
                style="width: min(560px, 100%); height: 100%; background-color: var(--color-surface); color: var(--color-text); z-index: 61; box-shadow: -8px 0 24px rgba(0,0,0,0.12);"
            >
                <div class="flex items-center justify-between gap-3 shrink-0" style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border);">
                    <h2 id="shop-filters-drawer-title" class="text-xl font-semibold m-0">Filters</h2>
                    <button
                        type="button"
                        x-ref="closeBtn"
                        @click="close()"
                        aria-label="Close filters"
                        class="inline-flex items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="width: 44px; height: 44px; border-radius: 9999px; border: 1px solid var(--color-border); background-color: var(--color-surface); color: var(--color-text); outline-color: var(--color-accent);"
                    >×</button>
                </div>
                <div class="flex-1 overflow-y-auto min-h-0" style="padding: 1rem 1.25rem;">
                    @include('shop.partials.filter-groups', ['groups' => $groups, 'state' => $state, 'inDrawer' => true])
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3 shrink-0" style="padding: 1rem 1.25rem; border-top: 1px solid var(--color-border);">
                    <a
                        href="{{ $clearUrl }}"
                        class="inline-flex items-center justify-center px-4 text-sm"
                        style="border: 1px solid var(--color-border); border-radius: var(--radius-button); min-height: 44px; color: var(--color-text); background-color: var(--color-surface);"
                    >Clear filters</a>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center px-4 text-sm"
                        style="background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); min-height: 44px;"
                    >Apply filters</button>
                </div>
            </form>
        </div>
    </template>
</div>
<script>
{!! preg_replace('/^export /m', '', (string) file_get_contents(resource_path('js/shop/filters.js'))) !!}
</script>
@elseif (! $indexMode)
    @include('shop.partials.listing-toolbar', [
        'listing' => $listing,
        'listingPath' => $listingPath,
        'showFilter' => false,
    ])
@endif
