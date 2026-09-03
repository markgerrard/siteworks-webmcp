{{-- Editorial numbered rows: full-width items, serif-scale index, hairline
     rules. Process steps; same item fields as classic (step is marker-only —
     01 indexes are derived chrome, no ordinal circles). --}}
{{-- Top padding tighter than section spacing: sits on a same-background
     neighbour (combined seam ≈ one section-spacing). When __surface is
     contrast the wrapper is a different background from its neighbours,
     so full site-section-spacing applies (the background change absorbs
     the seam). --}}
@php
    $items = array_values($section['items'] ?? []);
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $mutedOnWrapper = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
    $hairlineBase = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
@endphp
<div data-svc-variant="numbered-rows"
     @class(['site-section-spacing' => $isContrast, 'pt-10 lg:pt-12' => ! $isContrast])
     style="background-color: {{ $wrapperBg }};{{ $isContrast ? '' : ' padding-bottom: var(--section-spacing);' }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mb-6">
            @if (empty($section['__suppress_eyebrow']) && !empty($section['title']))
                <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            @endif
            @if (!empty($section['title']))
                <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textOnWrapper }};"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        </div>
        @if ($items !== [])
            <div class="mt-10" style="border-top: 1px solid color-mix(in oklab, {{ $hairlineBase }} 18%, transparent);">
                @foreach ($items as $i => $item)
                    <div class="grid grid-cols-[3.5rem_1fr] md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)] gap-x-6 md:gap-x-8 gap-y-1 items-baseline py-7"
                         style="border-bottom: 1px solid color-mix(in oklab, {{ $hairlineBase }} 18%, transparent);">
                        <span class="text-2xl md:text-3xl font-light tabular-nums" style="color: {{ $accentOnWrapper }};">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="text-lg md:text-xl font-bold" style="color: {{ $textOnWrapper }};"
                            {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<span class="hidden"{!! $editor("items.{$i}.step", 'plain') !!}></span>@endif{{ $item['title'] ?? '' }}</h3>
                        <div class="col-start-2 md:col-start-3 md:row-start-1 text-base leading-relaxed prose prose-base max-w-none" style="color: {{ $mutedOnWrapper }};"
                             {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
