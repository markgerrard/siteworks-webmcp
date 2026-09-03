@php
    $panel = $shopSearchPanel ?? \App\Support\Shop\ShopSearchPanel::for($site);
    $fieldStyle = 'width: 100%; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);';
    $firstCategory = $panel['firstCategory'];
@endphp
<style>
    /* Result / popular cards: even padding all round (negative margin keeps the grid gutters),
       rounded like the photo, soft tint on hover and on the keyboard-highlighted card. */
    .shop-search-card { padding: .5rem; margin: -.5rem; border-radius: var(--radius-card); transition: background-color 120ms ease; }
    .shop-search-card:hover, .shop-search-card[data-highlighted="true"] { background-color: var(--color-surface-alt); }
    @media (prefers-reduced-motion: reduce) { .shop-search-card { transition: none; } }
</style>
<div id="shop-search-panel"
     x-data="{}"
     x-show="open"
     x-cloak
     x-bind="panelBind"
     @keydown.escape.window="close()"
     @click.outside="close()"
     class="w-full border-t max-md:overflow-y-auto max-md:h-[calc(100dvh-var(--overlay-header-h,4.5rem))]"
     style="background-color: var(--color-surface); border-color: var(--color-border);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 py-4">
        <form method="GET" action="{{ $panel['searchUrl'] }}" role="search" class="flex flex-wrap items-end gap-3 max-w-full" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="followOrSubmit($event)">
            <input
                type="search"
                name="q"
                x-ref="q"
                x-model.debounce.250ms="q"
                value="{{ $panel['query'] }}"
                placeholder="Search the shop"
                aria-label="Search the shop"
                autocomplete="off"
                class="min-w-0 flex-1 max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="{{ $fieldStyle }}"
            >
            <button
                type="submit"
                class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="min-height: 44px; min-width: 44px; padding: 0.5rem 1.25rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
            >Search</button>
            <button type="button" @click="close()" aria-label="Close search"
                    class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="min-height: 44px; min-width: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); outline-color: var(--color-accent);">✕</button>
        </form>

        <div class="mt-4" x-show="q.trim().length < 2">
            @include('shop.partials.category-chips', [
                'categories' => $panel['categories'],
                'current' => $panel['currentCategorySlug'],
            ])

            @if ($panel['popular'] !== [])
                <h2 class="mt-6 text-sm font-bold tracking-widest uppercase" style="color: var(--color-accent);">Popular</h2>
                <ul class="mt-3 list-none m-0 p-0" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem;">
                    @foreach ($panel['popular'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="shop-search-card block focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="outline-color: var(--color-accent);">
                                <span class="block w-full overflow-hidden" style="aspect-ratio: 1 / 1; background-color: var(--color-surface-alt); border-radius: var(--radius-card);">
                                    @if (is_string($item['image_url']) && $item['image_url'] !== '')
                                        <img src="{{ $item['image_url'] }}" alt="" width="300" height="300" style="width: 100%; height: 100%; object-fit: cover;">
                                    @endif
                                </span>
                                <span class="block min-w-0 mt-2">
                                    <span class="block font-medium text-sm leading-snug">{{ $item['name'] }}</span>
                                    @if ($item['price_display'] !== '')
                                        <x-shop.price :amount="$item['price_display']" :vat="$panel['vat']" />
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="mt-4" x-show="q.trim().length >= 2" x-cloak>
            <h2 class="text-sm font-bold tracking-widest uppercase" style="color: var(--color-accent);">{{ \App\Support\Shop\ShopCopy::heading($site ?? null) }}</h2>
            {{-- x-show lives on a wrapper: Alpine toggles style.display on its element and would
                 wipe the inline display:grid on the list itself. --}}
            <div x-show="results.length > 0">
            <ul class="mt-3 list-none m-0 p-0" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem;">
                <template x-for="(item, index) in results" :key="item.slug">
                    <li>
                        <a :href="item.url"
                           :aria-current="highlighted === index ? 'true' : null"
                           @mouseenter="highlighted = index"
                           @mouseleave="highlighted = -1"
                           class="shop-search-card block focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                           style="outline-color: var(--color-accent);"
                           :data-highlighted="highlighted === index ? 'true' : null">
                            <span class="block w-full overflow-hidden" style="aspect-ratio: 1 / 1; background-color: var(--color-surface-alt); border-radius: var(--radius-card);">
                                <img x-show="item.image_url" :src="item.image_url" alt="" width="300" height="300" style="width: 100%; height: 100%; object-fit: cover;">
                            </span>
                            <span class="block min-w-0 mt-2">
                                <span class="block font-medium text-sm leading-snug" x-text="item.name"></span>
                                <span class="tabular-nums whitespace-nowrap" style="font-variant-numeric: tabular-nums">
                                    <span x-text="item.price_display"></span>@if ($panel['vat']) <span style="font-variant-caps: small-caps">inc. VAT</span>@endif
                                </span>
                            </span>
                        </a>
                    </li>
                </template>
            </ul>
            </div>
            <a x-show="count > 0" :href="seeAllUrl" class="mt-3 inline-flex items-center text-sm font-semibold" style="color: var(--color-accent);" x-text="'See all ' + count + ' results →'"></a>
            <p x-show="count === 0" class="mt-3 text-sm" style="color: var(--color-text-muted);">
                Nothing called ‘<span x-text="q"></span>’ yet — try a flavour, or browse {{ $firstCategory['name'] ?? 'the shop' }}.
            </p>
        </div>
    </div>
</div>
<script>
function shopSearch() {
    const reduceMotion = typeof window !== 'undefined'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return {
        open: false,
        q: '',
        results: [],
        count: 0,
        seeAllUrl: '',
        highlighted: -1,
        lastToggle: null,
        abort: null,
        reduceMotion,
        searchUrl: '',
        init() {
            this.q = this.$el.dataset.shopSearchQ || '';
            this.searchUrl = this.$el.dataset.shopSearchUrl || '';
            this.$watch('q', (value) => this.onQuery(value));
        },
        get panelBind() {
            return this.reduceMotion ? {} : { ['x-transition.opacity.duration.200ms']: true };
        },
        toggle(el) {
            if (this.open) {
                this.close();
                return;
            }
            this.lastToggle = el || this.lastToggle;
            this.open = true;
            document.querySelectorAll('[data-shop-search-toggle]').forEach((btn) => {
                btn.setAttribute('aria-expanded', 'true');
            });
            if (typeof window !== 'undefined' && window.innerWidth < 768) {
                document.body.style.overflow = 'hidden';
            }
            this.$nextTick(() => this.$refs.q && this.$refs.q.focus());
        },
        close() {
            this.open = false;
            document.querySelectorAll('[data-shop-search-toggle]').forEach((btn) => {
                btn.setAttribute('aria-expanded', 'false');
            });
            document.body.style.overflow = '';
            this.$nextTick(() => this.lastToggle && this.lastToggle.focus());
        },
        onQuery(value) {
            const q = (value || '').trim();
            if (q.length < 2) {
                this.results = [];
                this.count = 0;
                this.seeAllUrl = '';
                this.highlighted = -1;
                if (this.abort) {
                    this.abort.abort();
                    this.abort = null;
                }
                return;
            }
            this.fetchResults(q);
        },
        fetchResults(q) {
            if (this.abort) {
                this.abort.abort();
            }
            this.abort = new AbortController();
            const joiner = this.searchUrl.indexOf('?') === -1 ? '?' : '&';
            const url = this.searchUrl + joiner + 'q=' + encodeURIComponent(q);
            const requested = q;
            fetch(url, {
                headers: { Accept: 'application/json' },
                signal: this.abort.signal,
            }).then((res) => res.json()).then((data) => {
                if ((this.q || '').trim() !== requested) {
                    return;
                }
                this.results = (data.results || []).slice(0, 5);
                this.count = data.count ?? this.results.length;
                this.seeAllUrl = data.see_all_url || (this.searchUrl + joiner + 'q=' + encodeURIComponent(requested));
                this.highlighted = -1; // nothing pre-selected: hover or arrow keys pick a card; Enter submits the form
            }).catch((err) => {
                if (err && err.name === 'AbortError') {
                    return;
                }
            });
        },
        move(delta) {
            if (! this.results.length) {
                return;
            }
            if (this.highlighted < 0) {
                this.highlighted = delta > 0 ? 0 : this.results.length - 1;
                return;
            }
            const next = this.highlighted + delta;
            this.highlighted = ((next % this.results.length) + this.results.length) % this.results.length;
        },
        followOrSubmit(event) {
            if (this.highlighted >= 0 && this.results[this.highlighted]) {
                window.location.href = this.results[this.highlighted].url;
                return;
            }
            const form = event.target.closest('form');
            if (form) {
                form.submit();
            }
        },
    };
}
</script>
