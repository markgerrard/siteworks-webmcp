@props(['icon', 'label'])

{{-- One row in a "what you can do" list: small icon + label + body. --}}
<div class="flex items-start gap-3">
    <div class="flex size-6 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
        <flux:icon :name="$icon" class="size-3.5" />
    </div>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $label }}</p>
        <div class="mt-0.5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">
            {{ $slot }}
        </div>
    </div>
</div>
