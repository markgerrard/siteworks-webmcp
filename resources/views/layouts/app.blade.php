<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
@if (($width ?? 'full') === 'page')
<x-cp-page-width :width="$width">{{ $slot }}</x-cp-page-width>
@else
        {{ $slot }}
@endif
    </flux:main>
</x-layouts::app.sidebar>
