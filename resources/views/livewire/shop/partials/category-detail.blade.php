@php
    $editMetaTitle = (string) ($metaTitle[$editingId] ?? '');
    $editMetaDescription = (string) ($metaDescription[$editingId] ?? '');
    $editDescriptionLong = (string) ($descriptionLong[$editingId] ?? '');
    $editFaqs = array_values($faqs[$editingId] ?? []);
    $isVisible = ($visibility[$editingId] ?? $editing['visibility'] ?? 'visible') !== 'hidden';
    $visibleItems = collect($editingProducts);
    if ($itemsStatusFilter !== 'all') {
        $visibleItems = $visibleItems->where('status', $itemsStatusFilter)->values();
    }
    $storefrontUrl = $storefrontHost
        ? 'https://'.$storefrontHost.$editing['storefront_path']
        : null;
    $catBandPct = match ($editing['hero_height'] ?? 'medium') {
        'small' => 20,
        'large' => 40,
        default => 28,
    };
    $catMinY = (int) ($catBandPct / 2);
    $catMaxY = 100 - (int) ($catBandPct / 2);
    $catCropY = max($catMinY, min($catMaxY, $editing['bg_position_y'] ?? 50));
    $catZone = $editing['text_zone'] ?? 'middle-left';
    $catHeroOn = (bool) ($editing['hero_enabled'] ?? true);
    $catHeroMode = $editing['hero_mode'] ?? null;
    if ($catHeroMode === null) {
        $catHeroMode = ! $catHeroOn ? 'none' : (! empty($editing['hero_image_url']) ? 'custom' : 'shared');
    }
    $catName = $editName !== '' ? $editName : (string) ($editing['name'] ?? '');
    $catAccentChips = \App\Support\Shop\AccentWordChips::for($catName);
    $catAccentWord = (string) ($editing['hero_accent_word'] ?? '');
    $catTextStyle = is_string($editing['hero_text_style'] ?? null) ? $editing['hero_text_style'] : null;
@endphp

<div
    data-category-detail="{{ $editingId }}"
    class="space-y-6"
    x-data="{ cropModal: false, cropY: {{ $catCropY }} }"
>
    <div class="flex flex-wrap items-center gap-3">
        <button
            type="button"
            class="flex items-center gap-1 text-sm text-zinc-500 hover:underline dark:text-zinc-400"
            wire:click="closeEditor"
        >
            <flux:icon name="chevron-left" class="size-3.5 shrink-0" />
            Categories
        </button>
        <flux:heading id="category-detail-heading" size="lg" level="2" class="min-w-0 flex-1 truncate">
            {{ $editName !== '' ? $editName : $editing['name'] }}
        </flux:heading>
        <div class="ms-auto flex flex-wrap items-center gap-2">
            @if ($storefrontUrl && $isVisible)
                <a
                    href="{{ $storefrontUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-sm text-zinc-700 underline-offset-2 hover:underline dark:text-zinc-200"
                    data-category-view
                >View</a>
            @endif
            <flux:button
                variant="ghost"
                wire:click="delete({{ $editingId }})"
                wire:confirm="Delete {{ $editing['name'] }}? {{ $editing['product_count'] }} products will be unassigned."
                wire:target="delete({{ $editingId }})"
                class="text-zinc-500 dark:text-zinc-400"
            >Delete</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row">
                    @if ($editing['hero_image_url'])
                        <img
                            src="{{ $editing['hero_image_url'] }}"
                            alt=""
                            class="h-40 w-40 shrink-0 cursor-pointer rounded-lg object-cover"
                            data-category-hero-tile
                            x-on:click="cropModal = true"
                        >
                    @else
                        <button
                            type="button"
                            class="flex h-40 w-40 shrink-0 items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900"
                            data-category-hero-placeholder
                            x-on:click="$refs.categoryHeroGenerate?.focus()"
                        >
                            <flux:icon.photo class="size-8 text-zinc-400" />
                        </button>
                    @endif
                    <div class="min-w-0 flex-1 space-y-3">
                        <flux:input wire:model="editName" wire:blur="commitEditName" label="Name" />
                        <flux:error name="editName" />
                        <flux:error name="name" />
                        <div x-data="{ editing: {{ $editDescriptionLong !== '' ? 'true' : 'false' }} }">
                            <button
                                type="button"
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                                x-show="!editing"
                                x-on:click="editing = true"
                            >Add description</button>
                            <div x-show="editing">
                                <flux:textarea
                                    wire:model="descriptionLong.{{ $editingId }}"
                                    :label="$editDescriptionLong === '' ? 'Add description' : 'Description'"
                                    rows="6"
                                />
                                <flux:description>{{ mb_strlen($editDescriptionLong) }} characters. Formatting is limited to safe public-page markup.</flux:description>
                                <flux:error name="descriptionLong.{{ $editingId }}" />
                            </div>
                        </div>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg" level="3">Category items</flux:heading>
                    <flux:badge size="sm" data-items-count="{{ $editingProductsTotal }}">{{ $editingProductsTotal }}</flux:badge>
                </div>

                <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
                    <div class="flex items-center gap-1" role="group" aria-label="Items view">
                        <button
                            type="button"
                            class="rounded-md p-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            wire:click="$set('itemsView', 'grid')"
                            aria-pressed="{{ $itemsView === 'grid' ? 'true' : 'false' }}"
                            aria-label="Grid view"
                        >
                            <flux:icon.squares-2x2 class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-md p-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            wire:click="$set('itemsView', 'list')"
                            aria-pressed="{{ $itemsView === 'list' ? 'true' : 'false' }}"
                            aria-label="List view"
                        >
                            <flux:icon.bars-3 class="size-4" />
                        </button>
                    </div>

                    <flux:select wire:model="sort.{{ $editingId }}" label="Sort products by" class="lg:max-w-xs">
                        <flux:select.option value="manual">Manual</flux:select.option>
                        <flux:select.option value="name">Name</flux:select.option>
                        <flux:select.option value="newest">Newest</flux:select.option>
                        <flux:select.option value="price_asc">Price ↑</flux:select.option>
                        <flux:select.option value="price_desc">Price ↓</flux:select.option>
                    </flux:select>
                    <flux:error name="sort.{{ $editingId }}" />

                    <div class="flex flex-wrap gap-1" role="group" aria-label="Status filter">
                        @foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'archived' => 'Archived'] as $filter => $label)
                            <button
                                type="button"
                                class="rounded-full px-3 py-1 text-sm {{ $itemsStatusFilter === $filter ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}"
                                wire:click="$set('itemsStatusFilter', '{{ $filter }}')"
                                aria-pressed="{{ $itemsStatusFilter === $filter ? 'true' : 'false' }}"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($editingProductsTotal > count($editingProducts))
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400" data-items-cap="60">
                        showing {{ count($editingProducts) }} of {{ $editingProductsTotal }}
                    </p>
                @endif

                @if ($editingProductsTotal === 0)
                    <div class="mt-6 flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-12 dark:border-neutral-700">
                        <flux:heading size="lg" level="3">No products in this category yet</flux:heading>
                        <a
                            href="{{ route($listRoute, $siteId) }}"
                            class="mt-3 text-sm text-zinc-700 underline-offset-2 hover:underline dark:text-zinc-200"
                            wire:navigate
                        >Products</a>
                    </div>
                @elseif ($itemsView === 'list')
                    <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700" data-items-view="list">
                        @foreach ($visibleItems as $item)
                            <a
                                wire:key="item-row-{{ $item['id'] }}"
                                href="{{ route($editRoute, ['site' => $siteId, 'product' => $item['id']]) }}"
                                class="flex items-center gap-3 py-2"
                                data-product-card="{{ $item['id'] }}"
                                data-product-status="{{ $item['status'] }}"
                            >
                                @if ($item['image_url'])
                                    <img src="{{ $item['image_url'] }}" alt="" class="h-10 w-10 shrink-0 rounded object-cover">
                                @else
                                    <span class="h-10 w-10 shrink-0 rounded bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>
                                @endif
                                <span class="min-w-0 flex-1 truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $item['name'] }}</span>
                                @if ($item['status'] === 'draft')
                                    <flux:badge size="sm" color="amber">Draft</flux:badge>
                                @elseif ($item['status'] === 'archived')
                                    <flux:badge size="sm" color="zinc">Archived</flux:badge>
                                @endif
                                <span class="tabular-nums text-sm text-zinc-500 dark:text-zinc-400">
                                    @if ($item['price_cents'] === null)
                                        —
                                    @else
                                        {{ \App\Support\ShopMoney::display($item['price_cents'], $shopCurrency, $item['price_from']) }}
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 flex flex-wrap gap-4" data-items-view="grid">
                        @foreach ($visibleItems as $item)
                            <a
                                wire:key="item-card-{{ $item['id'] }}"
                                href="{{ route($editRoute, ['site' => $siteId, 'product' => $item['id']]) }}"
                                class="min-w-[10rem] flex-1 basis-[10rem] max-w-[14rem]"
                                data-product-card="{{ $item['id'] }}"
                                data-product-status="{{ $item['status'] }}"
                            >
                                <div class="relative">
                                    @if ($item['image_url'])
                                        <img src="{{ $item['image_url'] }}" alt="" class="aspect-square w-full rounded-lg object-cover">
                                    @else
                                        <div class="aspect-square w-full rounded-lg bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"></div>
                                    @endif
                                    @if ($item['status'] === 'draft')
                                        <span class="absolute bottom-2 right-2">
                                            <flux:badge size="sm" color="amber">Draft</flux:badge>
                                        </span>
                                    @elseif ($item['status'] === 'archived')
                                        <span class="absolute bottom-2 right-2">
                                            <flux:badge size="sm" color="zinc">Archived</flux:badge>
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-2 line-clamp-2 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $item['name'] }}</p>
                            </a>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        </div>

        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg" level="3">Settings</flux:heading>
                <div class="mt-4 space-y-4">
                    <flux:select wire:model="parentId.{{ $editingId }}" label="Parent">
                        <flux:select.option value="">Top level</flux:select.option>
                        @foreach ($editing['parent_options'] as $option)
                            <flux:select.option value="{{ $option['id'] }}" :disabled="$option['disabled']">
                                {{ $option['name'] }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="parentId.{{ $editingId }}" />

                    <flux:select wire:model="visibility.{{ $editingId }}" label="Visibility">
                        <flux:select.option value="visible">Visible</flux:select.option>
                        <flux:select.option value="hidden">Hidden</flux:select.option>
                    </flux:select>
                    <flux:error name="visibility.{{ $editingId }}" />

                    <flux:field>
                        <flux:checkbox wire:model="isAnchor.{{ $editingId }}" label="Anchor" />
                        <flux:description>When Anchor is on, this category page also lists products from its children.</flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>Meta title</flux:label>
                        <flux:input wire:model.live="metaTitle.{{ $editingId }}" maxlength="70" />
                        <flux:description>{{ mb_strlen($editMetaTitle) }}/70</flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>Meta description</flux:label>
                        <flux:textarea wire:model.live="metaDescription.{{ $editingId }}" maxlength="170" rows="3" />
                        <flux:description>{{ mb_strlen($editMetaDescription) }}/170</flux:description>
                    </flux:field>

                    <flux:button variant="primary" wire:click="saveEditor" wire:target="saveEditor">Save</flux:button>
                </div>
            </flux:card>

            <div data-category-hero-card>
            <flux:card>
                <flux:heading size="lg" level="3">Hero</flux:heading>
                <div class="mt-4 space-y-4">
                    @if (session('shop-hero-msg'))
                        <flux:callout variant="success" icon="check-circle">
                            {{ session('shop-hero-msg') }}
                        </flux:callout>
                    @endif

                    <div data-category-intro-band-toggle>
                        <label class="mb-1 block text-xs text-zinc-400">Intro band</label>
                        <flux:description>Tinted band with the category image and name above the products — an alternative to the hero.</flux:description>
                        <div class="mt-1.5 flex gap-1.5">
                            @php $catIntroBand = (bool) ($editing['intro_band'] ?? false); @endphp
                            <button type="button"
                                    wire:click="setCategoryIntroBand({{ $editingId }}, true)"
                                    class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                           {{ $catIntroBand
                                                ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                On
                            </button>
                            <button type="button"
                                    wire:click="setCategoryIntroBand({{ $editingId }}, false)"
                                    class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                           {{ ! $catIntroBand
                                                ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                Off
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-zinc-400">Hero</label>
                        <div class="flex gap-1.5">
                            @foreach (['none' => 'None', 'shared' => 'Shared', 'custom' => 'Custom'] as $modeVal => $modeLabel)
                                <button type="button"
                                        wire:click="setCategoryHeroMode({{ $editingId }}, '{{ $modeVal }}')"
                                        class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                               {{ $catHeroMode === $modeVal
                                                    ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                    : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                    {{ $modeLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-zinc-400">Text style</label>
                        <div class="flex gap-1.5">
                            <button type="button"
                                    wire:click="resetCategoryHeroTextStyle({{ $editingId }})"
                                    class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                           {{ $catTextStyle === null
                                                ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                Auto
                            </button>
                            @foreach (['plain' => 'Plain', 'boxed' => 'Boxed'] as $sVal => $sLabel)
                                <button type="button"
                                        wire:click="setCategoryHeroTextStyle({{ $editingId }}, '{{ $sVal }}')"
                                        class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                               {{ $catTextStyle === $sVal
                                                    ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                    : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                    {{ $sLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-zinc-400">Accent word</label>
                        <flux:description>Pick one word of the category name to show in the accent colour.</flux:description>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @php $isNoneSelected = $catAccentWord === ''; @endphp
                            <button type="button"
                                    wire:click="resetCategoryHeroAccentWord({{ $editingId }})"
                                    aria-pressed="{{ $isNoneSelected ? 'true' : 'false' }}"
                                    class="cursor-pointer rounded px-2.5 py-1 text-xs font-semibold transition-all
                                           {{ $isNoneSelected
                                                ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                None
                            </button>
                            @foreach ($catAccentChips as $chip)
                                @php $isChipSelected = $catAccentWord !== '' && mb_strtolower($chip) === mb_strtolower($catAccentWord); @endphp
                                <button type="button"
                                        @if ($isChipSelected)
                                            wire:click="resetCategoryHeroAccentWord({{ $editingId }})"
                                        @else
                                            wire:click="setCategoryHeroAccentWord({{ $editingId }}, @js($chip))"
                                        @endif
                                        aria-pressed="{{ $isChipSelected ? 'true' : 'false' }}"
                                        class="cursor-pointer rounded px-2.5 py-1 text-xs font-semibold transition-all
                                               {{ $isChipSelected
                                                    ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                    : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                    {{ $chip }}
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="accentWord" />
                    </div>

                    @if ($catHeroMode === 'custom')
                    <div class="aspect-video overflow-hidden rounded-lg border border-zinc-100 bg-zinc-50 dark:border-neutral-700 dark:bg-neutral-800 relative group flex items-center justify-center {{ $editing['hero_image_url'] ? 'cursor-pointer' : '' }}"
                         @if ($editing['hero_image_url']) x-on:click="cropModal = true" @endif>
                        @if ($editing['hero_image_url'])
                            <img src="{{ $editing['hero_image_url'] }}"
                                 alt="{{ $editing['hero_alt'] }}"
                                 class="h-full w-full object-cover"
                                 style="object-position: center {{ $catCropY }}%" />
                            <div class="absolute inset-x-0 top-0 bg-black/50 pointer-events-none"
                                 style="height: {{ max(0, $catCropY - $catBandPct / 2) }}%"></div>
                            <div class="absolute inset-x-0 bottom-0 bg-black/50 pointer-events-none"
                                 style="height: {{ max(0, 100 - $catCropY - $catBandPct / 2) }}%"></div>
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-all flex items-center justify-center">
                                <span class="text-white text-xs font-medium opacity-0 group-hover:opacity-100 transition-opacity drop-shadow-md">Adjust Crop</span>
                            </div>
                        @else
                            <span class="text-xs text-zinc-400">No hero yet</span>
                        @endif
                    </div>

                    @if ($editing['hero_image_url'])
                        <template x-teleport="body">
                            <div x-show="cropModal" x-cloak x-transition.opacity
                                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                                 x-on:keydown.escape.window="cropModal = false">
                                <div class="bg-white dark:bg-neutral-900 rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden"
                                     x-on:click.away="cropModal = false"
                                     x-trap.noscroll="cropModal">
                                    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-200 dark:border-neutral-700">
                                        <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Adjust Vertical Crop — {{ $editing['name'] }}</h3>
                                        <button type="button" x-on:click="cropModal = false"
                                                class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 cursor-pointer">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="p-5 space-y-4">
                                        <div class="relative rounded-lg overflow-hidden border border-zinc-200 dark:border-neutral-700 mx-auto">
                                            <img src="{{ $editing['hero_image_url'] }}" alt="full image" class="w-full block" />
                                            <div class="absolute inset-x-0 top-0 bg-black/60 transition-all pointer-events-none"
                                                 x-bind:style="'height: ' + Math.max(0, cropY - {{ $catBandPct }}/2) + '%'"></div>
                                            <div class="absolute inset-x-0 bottom-0 bg-black/60 transition-all pointer-events-none"
                                                 x-bind:style="'height: ' + Math.max(0, (100 - cropY - {{ $catBandPct }}/2)) + '%'"></div>
                                            <div class="absolute inset-x-0 h-px bg-white/80 pointer-events-none transition-all"
                                                 x-bind:style="'top: ' + Math.max(0, cropY - {{ $catBandPct }}/2) + '%'"></div>
                                            <div class="absolute inset-x-0 h-px bg-white/80 pointer-events-none transition-all"
                                                 x-bind:style="'top: ' + Math.min(100, cropY + {{ $catBandPct }}/2) + '%'"></div>
                                            <div class="absolute right-2 text-xs text-white/90 font-mono bg-black/50 px-1.5 py-0.5 rounded pointer-events-none"
                                                 x-bind:style="'top: ' + cropY + '%'"
                                                 x-text="'{{ $editing['hero_height'] ?? 'medium' }}'"></div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-xs text-zinc-400 w-8">Top</span>
                                            <input type="range" min="{{ $catMinY }}" max="{{ $catMaxY }}" step="1"
                                                   x-model.number="cropY"
                                                   class="flex-1 accent-zinc-700" />
                                            <span class="text-xs text-zinc-400 w-12">Bottom</span>
                                            <span class="text-xs font-mono text-zinc-500 dark:text-zinc-400 w-10 text-right"
                                                  x-text="cropY + '%'"></span>
                                        </div>
                                        <p class="text-xs text-zinc-400">Highlighted band shows the visible hero area.</p>
                                        <div class="flex justify-end gap-2">
                                            <flux:button size="sm" variant="ghost" x-on:click="cropModal = false">Cancel</flux:button>
                                            <flux:button size="sm" variant="primary" icon="check"
                                                         x-on:click="$wire.setCategoryBgPositionY({{ $editingId }}, cropY); cropModal = false">
                                                Apply
                                            </flux:button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    @endif

                    <div>
                        <label class="mb-1 block text-xs text-zinc-400">Hero Height</label>
                        <div class="flex gap-1.5">
                            @foreach (['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'] as $hVal => $hLabel)
                                <button type="button"
                                        wire:click="setCategoryHeroHeight({{ $editingId }}, '{{ $hVal }}')"
                                        class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                               {{ ($editing['hero_height'] ?? 'medium') === $hVal
                                                    ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                    : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                    {{ $hLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-zinc-400">Hero Width</label>
                        <div class="flex gap-1.5">
                            @foreach (['boxed' => 'Boxed', 'full' => 'Full'] as $wVal => $wLabel)
                                <button type="button"
                                        wire:click="setCategoryHeroWidth({{ $editingId }}, '{{ $wVal }}')"
                                        class="flex-1 cursor-pointer rounded py-1.5 text-xs font-semibold transition-all
                                               {{ ($editing['hero_width'] ?? 'boxed') === $wVal
                                                    ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                    : 'bg-zinc-100 text-zinc-500 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-400 dark:hover:bg-neutral-600' }}">
                                    {{ $wLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if ($editing['hero_image_url'])
                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label class="text-xs text-zinc-400">Text Position</label>
                                <button type="button"
                                        wire:click="resetCategoryTextZone({{ $editingId }})"
                                        class="cursor-pointer text-xs text-amber-600 underline underline-offset-2 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300">
                                    Reset
                                </button>
                            </div>
                            <div class="inline-grid grid-cols-3 gap-1 rounded-lg bg-zinc-100 p-1.5 dark:bg-neutral-800">
                                @foreach (['top-left','top-center','top-right','middle-left','middle-center','middle-right','bottom-left','bottom-center','bottom-right'] as $zone)
                                    @php
                                        [$zRow, $zCol] = explode('-', $zone);
                                        $label = strtoupper(substr($zRow, 0, 1)).strtoupper(substr($zCol, 0, 1));
                                        $isActive = $catZone === $zone;
                                    @endphp
                                    <button type="button"
                                            wire:click="setCategoryTextZone({{ $editingId }}, '{{ $zone }}')"
                                            class="h-8 w-10 cursor-pointer rounded text-xs font-bold transition-all
                                                   {{ $isActive
                                                        ? 'bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900'
                                                        : 'bg-white text-zinc-400 hover:bg-zinc-200 dark:bg-neutral-700 dark:text-zinc-500 dark:hover:bg-neutral-600' }}"
                                            title="{{ ucwords(str_replace('-', ' ', $zone)) }}">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @unless ($demo)
                    <span x-ref="categoryHeroGenerate" class="inline-flex">
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="arrow-path"
                        wire:click="generateCategoryHero({{ $editingId }})"
                        wire:loading.attr="disabled"
                        wire:target="generateCategoryHero({{ $editingId }})"
                    >
                        <span wire:loading.remove wire:target="generateCategoryHero({{ $editingId }})">
                            {{ $editing['hero_image_url'] ? 'Regenerate Hero' : 'Generate Hero' }}
                        </span>
                        <span wire:loading wire:target="generateCategoryHero({{ $editingId }})">Generating…</span>
                    </flux:button>
                    </span>
                    @endunless

                    @if (count($heroVersions) > 1)
                        <div x-data="{ showVersions: false }">
                            <button type="button" x-on:click="showVersions = !showVersions"
                                    class="cursor-pointer text-xs text-amber-600 underline underline-offset-2 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300">
                                <span x-text="showVersions ? 'Hide' : 'Show'"></span> version history ({{ count($heroVersions) }})
                            </button>
                            <div x-show="showVersions" x-cloak class="mt-2 grid grid-cols-3 gap-2">
                                @foreach ($heroVersions as $v)
                                    <div class="space-y-1 overflow-hidden rounded border border-zinc-100 p-1.5 dark:border-neutral-700">
                                        <img src="{{ $v['image_url'] }}" alt="v{{ $loop->iteration }}" class="aspect-video w-full rounded object-cover" />
                                        <div class="flex items-center justify-between gap-1">
                                            <span class="truncate text-[9px] text-zinc-400">{{ $v['created_at'] }}</span>
                                            <flux:button size="xs" wire:click="selectVersion({{ $v['id'] }})">Use</flux:button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @endif
                </div>
            </flux:card>
            </div>

            <flux:card>
                <flux:heading size="lg" level="3">Content</flux:heading>
                <div class="mt-4 space-y-4">
                    <div
                        wire:poll.2s="pollContentDraft"
                        x-data="{ draftTimedOut: false }"
                        x-init="const startDraftTimeout = () => { draftTimedOut = false; window.setTimeout(() => { if ($wire.contentDraftStatus === 'drafting') { draftTimedOut = true } }, 60000) }; $watch('$wire.contentDraftStatus', (status) => { if (status === 'drafting') { startDraftTimeout() } else { draftTimedOut = false } }); if ($wire.contentDraftStatus === 'drafting') { startDraftTimeout() }"
                        class="rounded border p-3"
                        style="border-color: var(--color-border);"
                    >
                        @unless ($demo)
                        <flux:button wire:click="draftContent" wire:target="draftContent" :disabled="$contentDraftStatus === 'drafting'">AI: draft copy &amp; FAQs</flux:button>
                        @endunless
                        @if ($contentDraftStatus === 'drafting')
                            <p x-show="!draftTimedOut" class="mt-2 text-sm" style="color: var(--color-text-muted);">Drafting…</p>
                            <p x-show="draftTimedOut" class="mt-2 text-sm" style="color: var(--color-text-muted);">Couldn't draft copy — try again.</p>
                        @elseif ($contentDraftStatus === 'rate_limited')
                            <p class="mt-2 text-sm" style="color: var(--color-text-muted);">Couldn't draft copy — try again later.</p>
                        @elseif ($contentDraftStatus === 'failed')
                            <p class="mt-2 text-sm" style="color: var(--color-text-muted);">Couldn't draft copy — try again.</p>
                        @elseif ($contentDraft !== null)
                            <p class="mt-2 text-sm" style="color: var(--color-text-muted);">Draft ready. It has not changed this category.</p>
                            <div class="mt-2 flex gap-2">
                                <flux:button size="sm" variant="primary" wire:click="useContentDraft">Use draft</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="discardContentDraft">Discard</flux:button>
                            </div>
                        @endif
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <flux:heading size="sm" level="4">Frequently asked questions</flux:heading>
                            <flux:button size="sm" wire:click="addFaq" :disabled="count($editFaqs) >= 12">Add FAQ</flux:button>
                        </div>
                        @foreach ($editFaqs as $faq)
                            <div wire:key="category-faq-{{ $editingId }}-{{ $loop->index }}" class="space-y-2 rounded border p-3" style="border-color: var(--color-border);">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" wire:click="moveFaq({{ $loop->index }}, {{ $loop->index - 1 }})" :disabled="$loop->index === 0">Up</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="moveFaq({{ $loop->index }}, {{ $loop->index + 1 }})" :disabled="$loop->index === count($editFaqs) - 1">Down</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="removeFaq({{ $loop->index }})">Remove</flux:button>
                                </div>
                                <flux:input wire:model="faqs.{{ $editingId }}.{{ $loop->index }}.q" label="Question" maxlength="160" />
                                <flux:textarea wire:model="faqs.{{ $editingId }}.{{ $loop->index }}.a" label="Answer" maxlength="1200" rows="4" />
                            </div>
                        @endforeach
                    </div>
                </div>
            </flux:card>
        </div>
    </div>
</div>
