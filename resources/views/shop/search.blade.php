@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $snapshot = app(\App\Services\Shop\SnapshotReader::class)->forSite($site->id) ?? [];
    $snapshotProducts = $snapshot['products'] ?? [];
    $resultCount = count($products);
    $panel = \App\Support\Shop\ShopSearchPanel::for($site);
    $shopNoun = \App\Support\Shop\ShopCopy::pair($site);
    $firstCategory = $panel['firstCategory'];
    $emptyMessage = $firstCategory
        ? "Nothing called ‘{$query}’ yet — try a flavour, or browse {$firstCategory['name']}."
        : "Nothing called ‘{$query}’ yet — try a flavour, or browse the shop.";
    $emptyAction = $firstCategory['name'] ?? 'Browse the shop';
    $emptyHref = $firstCategory
        ? \App\Support\Shop\ShopUrls::collection($firstCategory['path'] ?? $firstCategory['slug'])
        : '/shop';
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Search'],
        ]" />

        @if ($query !== '')
            <h1 class="text-2xl font-bold mt-4 mb-4">{{ $resultCount }} {{ $resultCount === 1 ? $shopNoun['singular'] : $shopNoun['plural'] }} matching ‘{{ $query }}’</h1>
        @else
            <h1 class="text-2xl font-bold mt-4 mb-2">Search the shop</h1>
            <p class="mb-4 text-sm" style="color: var(--color-text-muted);">Use the search icon in the header to look for a bake.</p>
        @endif

        <div class="mb-6">
            @include('shop.partials.category-chips', [
                'categories' => $panel['categories'],
                'current' => null,
            ])
        </div>

        @if ($query !== '')
            @if ($resultCount === 0)
                <x-shop.empty-state :message="$emptyMessage" :action="$emptyAction" :href="$emptyHref" />
            @else
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full">
                    @foreach ($products as $p)
                        @php
                            $card = $snapshotProducts[$p->slug] ?? [
                                'slug' => $p->slug,
                                'image_urls' => null,
                                'product_card' => ['name' => $p->name, 'price_display' => ''],
                                'in_stock_any' => true,
                            ];
                        @endphp
                        @include('shop.partials.product-card', ['product' => $card])
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-shop.layout>
