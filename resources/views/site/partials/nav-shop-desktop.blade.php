@php
    $shopNavStyle = $item['shop_nav_style'] ?? 'dropdown';
    $shopTriggerClass = $shopTriggerClass ?? ('text-sm font-medium transition-colors'.($effectiveOverlay ?? false ? ' js-ovl' : ' '.$navLinkClass).$navCaseClass.' flex items-center gap-1');
    $shopChildClass = $shopChildClass ?? ('block px-4 py-2 text-sm transition-colors '.$navPanelLinkClass.$navCaseClass);
    $shopIndentClass = $shopIndentClass ?? ('block px-6 py-2 text-sm transition-colors '.$navPanelLinkClass.$navCaseClass);
    $shopOverlayClassAttr = ($effectiveOverlay ?? false)
        ? ' :class="(! scrolled) ? \'text-white/85 hover:text-white\' : \''.e($navLinkClass).'\'"'
        : '';
    $shopMegaXDataExtra = '';
    $shopMegaOpenExtra = '';
    if ($shopNavStyle === 'mega') {
        // fixed panel (escapes the trigger containing block) + hover bridge so
        // @mouseleave does not fire in the header-row gap. headerBox() falls
        // back to the nav row when the centred <header> is display:contents
        // (including sticky_shrink: off, which has no positioned ancestor).
        $shopMegaXDataExtra = ', panelTop: 0, bridgeTop: 0, bridgeH: 0, closeT: null, escapedAt: 0, headerBox() { const t = this.$refs.shopTrigger; if (!t) return null; const header = t.closest(\'header\'); if (header && getComputedStyle(header).display !== \'contents\') return header; return t.closest(\'[data-chrome-sticky=nav]\') || t.closest(\'nav\') || t; }, place() { const t = this.$refs.shopTrigger; const box = this.headerBox(); if (!t || !box) return; const hb = box.getBoundingClientRect().bottom; const tb = t.getBoundingClientRect().bottom; this.panelTop = hb; this.bridgeTop = tb; this.bridgeH = Math.max(0, hb - tb); this.$nextTick(() => { const p = this.$refs.shopPanel; if (!p || !this.open) return; const delta = hb - p.getBoundingClientRect().top; if (Math.abs(delta) > 0.5) { this.panelTop += delta; this.bridgeTop += delta; } }); }, inBand(e) { const t = this.$refs.shopTrigger; if (!t) return false; const tr = t.getBoundingClientRect(); return e.clientY >= tr.top - 1 && e.clientY <= this.bridgeTop + this.bridgeH + 1; }, init() { const r = () => { if (this.open) this.place(); }; window.addEventListener(\'resize\', r); window.addEventListener(\'scroll\', r, true); window.addEventListener(\'mousemove\', (e) => { if (this.open && this.inBand(e)) { clearTimeout(this.closeT); } }); }';
        $shopMegaOpenExtra = '; clearTimeout(closeT); place()';
        $shopMegaEnterExpr = 'if (window.innerWidth >= 768 && Date.now() - escapedAt > 400) { open = true; clearTimeout(closeT); place() }';
        $shopMegaEscExtra = '; escapedAt = Date.now()';
        $shopMegaLeaveExpr = 'if (window.innerWidth >= 768) { clearTimeout(closeT); closeT = setTimeout(() => { open = false }, 350) }';
    }
@endphp
<div x-data="{ open: false{!! $shopMegaXDataExtra !!} }" class="relative" data-shop-nav-style="{{ $shopNavStyle }}"
     @mouseenter="{!! $shopMegaEnterExpr ?? 'if (window.innerWidth >= 768) open = true' !!}"
     @mouseleave="{!! $shopMegaLeaveExpr ?? 'if (window.innerWidth >= 768) open = false' !!}"
     x-on:keydown.escape.window="if (open) { open = false{!! $shopMegaEscExtra ?? '' !!}; $nextTick(() => $refs.shopTrigger?.focus()) }">
    <a href="{{ $item['href'] }}"
       x-ref="shopTrigger"
       aria-haspopup="true"
       :aria-expanded="open ? 'true' : 'false'"
       @keydown.arrow-down.prevent="open = true{!! $shopMegaOpenExtra !!}"
       class="{{ $shopTriggerClass }}"{!! $shopOverlayClassAttr !!}>
        {{ $item['label'] }}
        <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </a>
    @if ($shopNavStyle === 'mega')
        <div data-shop-mega-bridge aria-hidden="true"
             :style="'position: fixed; left: 0; right: 0; pointer-events: none; top: ' + bridgeTop + 'px; height: ' + (open ? bridgeH : 0) + 'px'"></div>
        <div x-show="open" x-cloak x-ref="shopPanel"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             data-shop-mega-panel
             class="z-50 border border-gray-200 py-4 rounded-lg shadow-lg"
             :style="'background-color: var(--color-surface); position: fixed; left: 0; right: 0; width: auto; margin: 0; top: ' + panelTop + 'px'">
            <div class="site-shell-container px-4 grid gap-6 md:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
                @foreach ($item['children'] ?? [] as $child)
                    <div>
                        <a href="{{ $child['href'] }}" class="{{ $shopChildClass }}">
                            {{ $child['label'] }}
                        </a>
                        @foreach ($child['children'] ?? [] as $grand)
                            <a href="{{ $grand['href'] }}" class="{{ $shopIndentClass }}">
                                {{ $grand['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <div class="site-shell-container px-4 pt-2">
                <a href="{{ $item['all_products_href'] ?? '/shop' }}" class="{{ $shopChildClass }}">All products →</a>
            </div>
        </div>
    @else
        <div x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="absolute top-full left-0 mt-2 w-64 rounded-lg shadow-lg border border-gray-200 py-2 z-50"
             style="{{ $navPanelStyle }}">
            @foreach ($item['children'] ?? [] as $child)
                <a href="{{ $child['href'] }}" class="{{ $shopChildClass }}">
                    {{ $child['label'] }}@if (($child['children'] ?? []) !== []) <span aria-hidden="true">›</span>@endif
                </a>
                @foreach ($child['children'] ?? [] as $grand)
                    <a href="{{ $grand['href'] }}" class="{{ $shopIndentClass }}">
                        {{ $grand['label'] }}
                    </a>
                @endforeach
            @endforeach
        </div>
    @endif
</div>
