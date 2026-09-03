@php
    $shopMobileLinkClass = $shopMobileLinkClass ?? ('block px-3 py-2.5 text-sm font-medium '.$navMobileLinkClass.' rounded-md'.$navCaseClass);
    $shopMobileChildClass = $shopMobileChildClass ?? ('block px-6 py-2 text-sm font-medium '.$navMobileLinkClass.' rounded-md'.$navCaseClass);
@endphp
<div class="space-y-1" data-shop-nav-mobile data-shop-nav-style="{{ $item['shop_nav_style'] ?? 'dropdown' }}" x-data="{ shopOpen: false }">
    <div class="flex items-center gap-1">
        <a href="{{ $item['href'] }}" @click="mobileNav = false" class="{{ $shopMobileLinkClass }} flex-1">
            {{ $item['label'] }}
        </a>
        <button type="button" @click="shopOpen = !shopOpen" class="p-2 {{ $navMobileLinkClass }} rounded-md"
                x-bind:aria-expanded="shopOpen ? 'true' : 'false'" aria-label="Shop categories">
            <svg class="w-3.5 h-3.5 transition-transform" :class="shopOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </div>
    <div x-show="shopOpen" x-cloak class="space-y-1">
        @foreach ($item['children'] ?? [] as $child)
            @if (($child['children'] ?? []) !== [])
                <div x-data="{ open: false }">
                    <div class="flex items-center gap-1">
                        <a href="{{ $child['href'] }}" @click="mobileNav = false" class="{{ $shopMobileChildClass }} flex-1">
                            {{ $child['label'] }}
                        </a>
                        <button type="button" @click="open = !open" class="p-2 {{ $navMobileLinkClass }} rounded-md"
                                x-bind:aria-expanded="open ? 'true' : 'false'" aria-label="{{ $child['label'] }} subcategories">
                            <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                    <div x-show="open" x-cloak>
                        @foreach ($child['children'] as $grand)
                            <a href="{{ $grand['href'] }}" @click="mobileNav = false" class="{{ $shopMobileChildClass }}">
                                {{ $grand['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                <a href="{{ $child['href'] }}" @click="mobileNav = false" class="{{ $shopMobileChildClass }}">
                    {{ $child['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</div>
