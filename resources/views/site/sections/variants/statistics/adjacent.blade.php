@php
    $prev = $section['__previous_surface'] ?? null;
    $prevClass = (is_string($prev) && preg_match('/^[a-z0-9-]+$/', $prev) === 1)
        ? 'previous-'.$prev
        : '';
    $items = is_array($items ?? null) ? array_values($items) : [];
    $eyebrow = $section['eyebrow'] ?? 'By the numbers';
@endphp
@if ($prevClass === 'previous-contrast')
<style>
    .statistics-adjacent.previous-contrast { padding-top: 0; background-color: var(--color-surface-contrast); color: var(--color-text-on-contrast); }
</style>
@elseif ($prevClass === 'previous-brand')
<style>
    .statistics-adjacent.previous-brand { padding-top: 0; background-color: var(--brand-primary); color: var(--color-text-on-primary, #ffffff); }
</style>
@endif
<div data-svc-variant="adjacent" class="site-section-spacing statistics-adjacent {{ $prevClass }}" style="background-color: var(--color-surface);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']) || ! empty($section['eyebrow']) || ! empty($section['intro']))
            <div class="text-center mb-12">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                @if (!empty($section['title']))
                    <h2 class="text-3xl md:text-4xl font-extrabold" {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
                @if (!empty($section['intro']))
                    <p class="mt-3 text-lg max-w-2xl mx-auto" style="color: var(--color-text-muted);" {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                @endif
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
            @endif
        @endif

        @if ($items !== [])
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2.5rem;">
                @foreach ($items as $i => $item)
                    <div class="text-center" style="flex: 0 1 12rem;">
                        <p class="text-4xl md:text-5xl font-extrabold tabular-nums" {!! $editor("items.{$i}.prefix", 'plain') !!}{!! $editor("items.{$i}.value", 'plain') !!}{!! $editor("items.{$i}.suffix", 'plain') !!}>{{ $item['prefix'] ?? '' }}{{ $item['value'] ?? '' }}{{ $item['suffix'] ?? '' }}</p>
                        @if (!empty($item['label']))
                            <p class="mt-2 text-sm font-semibold tracking-wide uppercase" style="color: var(--color-text-muted);" {!! $editor("items.{$i}.label", 'plain') !!}>{{ $item['label'] }}</p>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("items.{$i}.label", 'plain') !!}></span>
                        @endif
                        @if (!empty($item['description']))
                            <p class="mt-1 text-sm" style="color: var(--color-text-muted);" {!! $editor("items.{$i}.description", 'plain') !!}>{{ $item['description'] }}</p>
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor("items.{$i}.description", 'plain') !!}></span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
