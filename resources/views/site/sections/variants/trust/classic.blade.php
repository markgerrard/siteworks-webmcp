@php
    // Clamp items to three: the equal-card grid is a 3-col layout.
    // Dispatcher passes the full list so new variants can render all.
    $items = array_slice(array_values($items ?? []), 0, 3);
@endphp

@php
    // When __surface is contrast the wrapper is a different background
    // from its neighbours, so full site-section-spacing applies (the
    // background change absorbs the seam).
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface-alt)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text-on-alt)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text-on-alt)';
@endphp
<div class="site-section-spacing" style="background-color: {{ $wrapperBg }};">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            @php
                // The content writer often returns "Why Choose Us" as the title, which
                // would duplicate the hardcoded eyebrow. Skip the eyebrow
                // when they collide (case-insensitive). Section now sits on
                // surface-alt (consistent dark/light theme surface used
                // elsewhere on the page) instead of raw brand-primary —
                // vivid mid-saturation primaries (e.g. #00aeef cyan) read
                // as washed out when used as a full-bleed band even with
                // WCAG-derived text colours.
                $eyebrow = $section['eyebrow'] ?? 'Why Choose Us';
                $titleMatchesEyebrow = strcasecmp(trim($section['title']), $eyebrow) === 0;
                $hideEyebrow = $titleMatchesEyebrow || ! empty($section['__suppress_eyebrow']);
            @endphp
            <div class="text-center mb-16">
                @unless ($hideEyebrow)
                    <span class="text-sm font-bold tracking-widest uppercase mb-4 block"
                          style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endunless
                <h2 class="text-4xl md:text-5xl font-extrabold leading-tight text-balance"
                    style="color: {{ $textOnWrapper }};"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif

        @if (!empty($items))
            {{-- Compiled site CSS lacks sm:flex-row, so the mobile stack is a
                 scoped style block (same pattern as reviews_summary). --}}
            <style>
                @media (max-width: 639px) {
                    .trust-item-card {
                        flex-direction: column;
                    }
                }
            </style>
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($items as $i => $item)
                    <div class="trust-item-card flex items-start gap-5 p-8 rounded-lg"
                         style="flex: 0 1 calc(33.333% - 1.34rem); min-width: 280px; background-color: var(--color-surface); border: 1px solid var(--color-border);">
                        {{-- Brand-primary still carries the brand identity for
                             this section via the icon tile: cyan box, glyph
                             drawn in WCAG-derived text-on-primary. Pops
                             against the surface-alt section bg without
                             needing a full-bleed brand band. --}}
                        <div class="flex-shrink-0 w-14 h-14 rounded-md flex items-center justify-center shadow-md"
                             style="background-color: var(--brand-primary); color: var(--color-text-on-primary, #ffffff);">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-bold text-xl md:text-2xl leading-snug"
                                style="color: var(--color-text-on-alt);"
                                {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                            <p class="mt-2 text-base leading-relaxed"
                               style="color: var(--color-text-muted-on-alt);"
                               {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
