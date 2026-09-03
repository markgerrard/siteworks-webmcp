<div>
    @if ($sites->count() <= 1)
        <div class="text-sm font-semibold truncate text-white">{{ $active?->business_name ?? __('No site') }}</div>
        @if ($active?->custom_domain || $active?->preview_domain)
            <div class="text-xs text-zinc-400 truncate">
                {{ $active->custom_domain ?: $active->preview_domain }}
            </div>
        @endif
    @else
        <flux:dropdown align="start" class="w-full">
            <flux:button variant="ghost" class="w-full justify-between" icon-trailing="chevron-down">
                <span class="truncate">{{ $active?->business_name ?? __('Switch site') }}</span>
            </flux:button>
            <flux:menu>
                <flux:menu.item disabled>{{ __('Switch site') }}</flux:menu.item>
                <flux:menu.separator />
                @foreach ($sites as $option)
                    <flux:menu.item :href="route('client.portal.site', $option)" wire:navigate>
                        <div class="flex flex-col">
                            <span class="font-medium">{{ $option->business_name }}</span>
                            @if ($option->custom_domain || $option->preview_domain)
                                <span class="text-xs text-zinc-500">
                                    {{ $option->custom_domain ?: $option->preview_domain }}
                                </span>
                            @endif
                        </div>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
