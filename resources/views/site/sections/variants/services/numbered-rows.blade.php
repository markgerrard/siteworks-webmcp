{{-- Editorial numbered rows: shipped features/numbered chrome on
     services fields. Featured / contact-cta tiles flatten to equal
     rows (01 ledger, no ordinal circles, no card photos in v1).
     Top padding tighter than section spacing when this band shares
     its neighbour's background (see features/numbered). When
     __surface is contrast the wrapper is a different background
     from its neighbours, so full site-section-spacing applies (the
     background change absorbs the seam). --}}
@php
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
    $textColor = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $mutedColor = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted)';
    $accentColor = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
    $hairlineBase = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
@endphp
<div id="home-content"
     data-svc-variant="numbered-rows"
     @class([
         'scroll-mt-24 md:scroll-mt-28',
         'site-section-spacing' => $isContrast,
         'pt-10 lg:pt-12' => ! $isContrast,
     ])
     style="background-color: {{ $wrapperBg }};@unless ($isContrast) padding-bottom: var(--section-spacing);@endunless">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="max-w-3xl mb-6">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentColor }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textColor }};"
                    {!! $editor('title', 'plain') !!}>{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'], $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}</h2>
                @if (!empty($section['intro']))
                    <div class="mt-3 text-base md:text-lg prose" style="color: {{ $mutedColor }};"
                         {!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
                @endif
            </div>
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
        @endif

        @if (!empty($section['items']))
            <div class="mt-10" style="border-top: 1px solid color-mix(in oklab, {{ $hairlineBase }} 18%, transparent);">
                @foreach ($section['items'] as $i => $item)
                    <div class="grid grid-cols-[3.5rem_1fr] md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)] gap-x-6 md:gap-x-8 gap-y-1 items-baseline py-7"
                         style="border-bottom: 1px solid color-mix(in oklab, {{ $hairlineBase }} 18%, transparent);">
                        <span class="text-2xl md:text-3xl font-light tabular-nums" style="color: {{ $accentColor }};">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="text-lg md:text-xl font-bold" style="color: {{ $textColor }};"
                            {!! $editor("items.{$i}.title", 'plain') !!}>@if ($emitMarkers)<button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>@endif@if ($href = $resolveItemHrefForVariants($item))<a href="{{ $href }}" class="hover:underline" style="color: inherit;">{{ $item['title'] ?? '' }}</a>@else{{ $item['title'] ?? '' }}@endif</h3>
                        <div class="col-start-2 md:col-start-3 md:row-start-1 text-base leading-relaxed prose prose-sm" style="color: {{ $mutedColor }};"
                             {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
