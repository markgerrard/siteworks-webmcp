{{-- Precision markers listing: two-column dense + markers, clusters of 3
     with stronger dividers. Process steps; same item fields as classic
     (step is marker-only; + markers, no ordinal circles). --}}
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
<div data-svc-variant="marker-columns"
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
            <div class="md:columns-2 md:gap-x-16 mt-8">
                @foreach ($items as $i => $item)
                    <div class="break-inside-avoid grid grid-cols-[1.6rem_1fr] gap-3 py-5"
                         style="border-bottom: 1px solid color-mix(in oklab, {{ $hairlineBase }} {{ ($i + 1) % 3 === 0 ? '28' : '12' }}%, transparent);">
                        {{-- Numerals, not "+": process is the one sequence, and columns-2
                             flows down-first so row-scanners need the order disambiguated
                             (trust keeps "+" — semantic split between the look-alikes). --}}
                        <span class="text-sm font-light tabular-nums leading-6" style="color: {{ $accentOnWrapper }};">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <div>
                            <h3 class="text-base md:text-lg font-bold" style="color: {{ $textOnWrapper }};"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<span class="hidden"{!! $editor("items.{$i}.step", 'plain') !!}></span>@endif{{ $item['title'] ?? '' }}</h3>
                            <div class="mt-1 text-sm md:text-base leading-relaxed prose prose-base max-w-none" style="color: {{ $mutedOnWrapper }};"
                                 {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
