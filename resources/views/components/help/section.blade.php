@props(['icon', 'title'])

{{-- Top-of-help section: large icon + bold title + slot for body text. --}}
<div>
    <div class="flex items-center gap-2.5 text-zinc-800 dark:text-zinc-100">
        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent/15 text-accent">
            <flux:icon :name="$icon" class="size-4" />
        </div>
        <p class="font-semibold leading-none">{{ $title }}</p>
    </div>
    <div class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
        {{ $slot }}
    </div>
</div>
