<x-layouts::client :title="__('Design').' — '.$site->business_name" :site="$site" width="page">
    <x-slot:help>
        <x-help.section icon="paint-brush" title="Design">
            Seven sub-tabs control how your site looks. Changes apply
            instantly across every page.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="sparkles" label="Design Brief">
                Colour palette, typography, overall mood. Use this for a
                fresh look — modern, traditional, trades-ready.
            </x-help.action>
            <x-help.action icon="adjustments-horizontal" label="Options">
                Preview layout, hero size, layout toggles.
            </x-help.action>
            <x-help.action icon="photo" label="Logo">
                Upload your own — or pick from AI-generated options if you
                don't have one yet.
            </x-help.action>
            <x-help.action icon="squares-2x2" label="Layout">
                About and service page layouts. One choice each, applied
                across every matching page.
            </x-help.action>
            <x-help.action icon="bars-3" label="Header">
                Logo size and header chrome & type. Applies across the
                site.
            </x-help.action>
            <x-help.action icon="share" label="Share image">
                Preview, upload, or regenerate the card shown when your site is shared.
            </x-help.action>
            <x-help.action icon="shopping-bag" label="Storefront">
                Product fact tabs shoppers see on product pages.
            </x-help.action>
        </div>

        <x-help.tip>
            Open <strong>View</strong> in a new tab to compare your changes
            against the live site side-by-side.
        </x-help.tip>
    </x-slot:help>

    @php $designPillKey = 'siteworks.clientDesignTab.'.$site->id; @endphp
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6"
         x-data="{ designPill: localStorage.getItem('{{ $designPillKey }}') || 'design_brief' }"
         x-init="$watch('designPill', v => localStorage.setItem('{{ $designPillKey }}', v))">
        <flux:heading size="xl">{{ __('Design') }}</flux:heading>

        <div class="flex flex-wrap gap-3">
            @foreach ([
                'design_brief' => 'Design Brief',
                'options' => 'Options',
                'logo' => 'Logo',
                'layout' => 'Layout',
                'header' => 'Header',
                'share_image' => 'Share image',
                'storefront' => 'Storefront',
            ] as $key => $label)
                <button type="button" x-on:click="designPill = '{{ $key }}'"
                        :class="designPill === '{{ $key }}'
                            ? 'bg-accent text-accent-foreground border-accent shadow-sm'
                            : 'bg-transparent text-zinc-500 dark:text-zinc-400 border-zinc-300 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-zinc-800 dark:hover:text-zinc-100'"
                        class="text-xs font-semibold px-4 py-1.5 rounded-full border transition-colors cursor-pointer">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div x-show="designPill === 'design_brief'" x-cloak>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Design Brief') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:design-panel :siteId="$site->id" />
            </div>
        </div>

        <div x-show="designPill === 'options'" x-cloak class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Preview Layout') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:layout-picker :siteId="$site->id" />
            </div>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Layout Options') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:preview-toggles :siteId="$site->id" />
            </div>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Hero Height') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:hero-size-picker :siteId="$site->id" />
            </div>
        </div>

        <div x-show="designPill === 'logo'" x-cloak>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Logo') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:logo-picker :siteId="$site->id" />
            </div>
        </div>

        <div x-show="designPill === 'layout'" x-cloak class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('About Page Layout') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:page-layout-settings :site-id="$site->id" :kind="'about'" :key="'page-layout-about-'.$site->id" />
            </div>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Service Page Layout') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:page-layout-settings :site-id="$site->id" :kind="'service'" :key="'page-layout-service-'.$site->id" />
            </div>
        </div>

        <div x-show="designPill === 'share_image'" x-cloak>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Share image') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:share-image-panel :siteId="$site->id" />
            </div>
        </div>

        <div x-show="designPill === 'storefront'" x-cloak class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <livewire:shop.storefront-defaults :site-id="$site->id" :key="'storefront-defaults-'.$site->id" />
            </div>
            @if ($site->shopEnabled())
                <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                    <flux:heading size="lg" class="mb-4">{{ __('Tags & badges') }}</flux:heading>
                    <flux:separator class="mb-4" />
                    <livewire:shop.tags-badges-settings :site-id="$site->id" :key="'tags-badges-'.$site->id" />
                </div>
                <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                    <flux:heading size="lg" class="mb-4">{{ __('Shop index blocks') }}</flux:heading>
                    <flux:separator class="mb-4" />
                    <livewire:shop.shop-index-blocks-settings :site-id="$site->id" :key="'shop-index-blocks-'.$site->id" />
                </div>
            @endif
        </div>

        <div x-show="designPill === 'header'" x-cloak class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Logo Size') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:logo-size-settings :site-id="$site->id" :key="'logo-size-'.$site->id" />
            </div>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Chrome & type') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:header-style-settings :site-id="$site->id" :key="'header-style-'.$site->id" />
            </div>
        </div>

        <div x-show="designPill === 'storefront'" x-cloak class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <livewire:shop.storefront-defaults :site-id="$site->id" :key="'storefront-defaults-'.$site->id" />
            </div>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Product facts') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:shop.product-fact-groups :site-id="$site->id" :key="'product-fact-groups-'.$site->id" />
            </div>
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Product reviews') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:shop.product-reviews-settings :site-id="$site->id" :key="'product-reviews-settings-'.$site->id" />
            </div>
        </div>
    </div>
</x-layouts::client>
