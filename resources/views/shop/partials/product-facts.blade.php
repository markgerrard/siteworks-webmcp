@php
    $extraTabs = is_array($extraTabs ?? null) ? $extraTabs : [];
    $tabIds = array_merge(['description'], array_column($factTabs, 'slug'), array_column($extraTabs, 'slug'));
    $description = (string) ($description ?? '');
@endphp
<div
    class="mb-6"
    x-data="{
        js: false,
        tab: 'description',
        tabs: {{ json_encode($tabIds) }},
        select(id) { this.tab = id },
        move(delta) {
            const i = this.tabs.indexOf(this.tab);
            const next = this.tabs[(i + delta + this.tabs.length) % this.tabs.length];
            this.tab = next;
            this.$nextTick(() => this.$refs['tab-' + next]?.focus());
        },
    }"
    x-init="js = true"
>
    <div class="hidden md:block" x-show="js" x-cloak>
        <div role="tablist" aria-label="Product details" class="flex flex-wrap gap-2 border-b" style="border-color: var(--color-border);">
            <button
                type="button"
                role="tab"
                id="product-fact-tab-description"
                x-ref="tab-description"
                :aria-selected="tab === 'description' ? 'true' : 'false'"
                :tabindex="tab === 'description' ? 0 : -1"
                aria-controls="product-fact-panel-description"
                @click="select('description')"
                @keydown.arrow-right.prevent="move(1)"
                @keydown.arrow-left.prevent="move(-1)"
                class="px-3 py-2 text-sm font-medium"
                :class="tab === 'description' ? 'border-b-2' : ''"
                :style="tab === 'description' ? 'border-color: var(--color-accent);' : 'border-color: transparent;'"
            >Description</button>
            @foreach ($factTabs as $group)
                <button
                    type="button"
                    role="tab"
                    id="product-fact-tab-{{ $group['slug'] }}"
                    x-ref="tab-{{ $group['slug'] }}"
                    :aria-selected="tab === '{{ $group['slug'] }}' ? 'true' : 'false'"
                    :tabindex="tab === '{{ $group['slug'] }}' ? 0 : -1"
                    aria-controls="product-fact-panel-{{ $group['slug'] }}"
                    @click="select('{{ $group['slug'] }}')"
                    @keydown.arrow-right.prevent="move(1)"
                    @keydown.arrow-left.prevent="move(-1)"
                    class="px-3 py-2 text-sm font-medium"
                    :class="tab === '{{ $group['slug'] }}' ? 'border-b-2' : ''"
                    :style="tab === '{{ $group['slug'] }}' ? 'border-color: var(--color-accent);' : 'border-color: transparent;'"
                >{{ $group['label'] }}</button>
            @endforeach
            @foreach ($extraTabs as $tab)
                <button
                    type="button"
                    role="tab"
                    id="product-fact-tab-{{ $tab['slug'] }}"
                    x-ref="tab-{{ $tab['slug'] }}"
                    :aria-selected="tab === '{{ $tab['slug'] }}' ? 'true' : 'false'"
                    :tabindex="tab === '{{ $tab['slug'] }}' ? 0 : -1"
                    aria-controls="product-fact-panel-{{ $tab['slug'] }}"
                    @click="select('{{ $tab['slug'] }}')"
                    @keydown.arrow-right.prevent="move(1)"
                    @keydown.arrow-left.prevent="move(-1)"
                    class="px-3 py-2 text-sm font-medium"
                    :class="tab === '{{ $tab['slug'] }}' ? 'border-b-2' : ''"
                    :style="tab === '{{ $tab['slug'] }}' ? 'border-color: var(--color-accent);' : 'border-color: transparent;'"
                >{{ $tab['label'] }}</button>
            @endforeach
        </div>
        <div
            role="tabpanel"
            id="product-fact-panel-description"
            aria-labelledby="product-fact-tab-description"
            x-show="tab === 'description'"
            class="pt-4"
        >{!! nl2br(e($description)) !!}</div>
        @foreach ($factTabs as $group)
            <div
                role="tabpanel"
                id="product-fact-panel-{{ $group['slug'] }}"
                aria-labelledby="product-fact-tab-{{ $group['slug'] }}"
                x-show="tab === '{{ $group['slug'] }}'"
                class="pt-4"
            >
                @include('shop.partials.product-fact-value', ['group' => $group])
            </div>
        @endforeach
        @foreach ($extraTabs as $tab)
            <div
                role="tabpanel"
                id="product-fact-panel-{{ $tab['slug'] }}"
                aria-labelledby="product-fact-tab-{{ $tab['slug'] }}"
                x-show="tab === '{{ $tab['slug'] }}'"
                class="pt-4"
            >
                @include('shop.partials.product-reviews')
            </div>
        @endforeach
    </div>

    <div class="md:hidden space-y-2" x-show="js" x-cloak>
        <details open>
            <summary class="cursor-pointer font-medium py-2">Description</summary>
            <div class="pt-2">{!! nl2br(e($description)) !!}</div>
        </details>
        @foreach ($factTabs as $group)
            <details>
                <summary class="cursor-pointer font-medium py-2">{{ $group['label'] }}</summary>
                <div class="pt-2">
                    @include('shop.partials.product-fact-value', ['group' => $group])
                </div>
            </details>
        @endforeach
        @foreach ($extraTabs as $tab)
            <details>
                <summary class="cursor-pointer font-medium py-2">{{ $tab['label'] }}</summary>
                <div class="pt-2">
                    @include('shop.partials.product-reviews')
                </div>
            </details>
        @endforeach
    </div>

    <div x-show="!js">
        <h2 class="text-lg font-semibold mb-2">Description</h2>
        <div class="mb-4">{!! nl2br(e($description)) !!}</div>
        @foreach ($factTabs as $group)
            <h2 class="text-lg font-semibold mb-2">{{ $group['label'] }}</h2>
            <div class="mb-4">
                @include('shop.partials.product-fact-value', ['group' => $group])
            </div>
        @endforeach
        @foreach ($extraTabs as $tab)
            <h2 class="text-lg font-semibold mb-2">{{ $tab['label'] }}</h2>
            <div class="mb-4">
                @include('shop.partials.product-reviews')
            </div>
        @endforeach
    </div>
</div>
