@props(['icon' => 'light-bulb'])

{{-- Accent-tinted tip callout — small but visually distinct from the
     surrounding help body. --}}
<div class="rounded-lg border border-accent/30 bg-accent/10 p-3">
    <div class="flex items-start gap-2">
        <flux:icon :name="$icon" class="size-4 shrink-0 text-accent mt-0.5" />
        <div class="text-xs leading-relaxed text-zinc-700 dark:text-zinc-200">
            {{ $slot }}
        </div>
    </div>
</div>
