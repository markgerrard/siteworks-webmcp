{{-- Precision marker-columns: shipped features/markers chrome (+, md:columns-2,
     cluster-of-3 hairlines) on home services fields. Featured / contact_cta
     flatten to equal + rows; no photos, icons, or ordinal circles. --}}
{{-- When __surface is contrast the wrapper is a different background from its
     neighbours, so full site-section-spacing applies (the background change
     absorbs the seam). Unstamped, this is the first home content band after
     the hero (different surface), so full spacing also applies. --}}
@php
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface-alt)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text-on-alt)';
    $mutedOnWrapper = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted-on-alt)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text-on-alt)';
    $hairlineBase = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
    $items = is_array($section['items'] ?? null) ? array_values($section['items']) : [];
@endphp
<div id="home-content" data-svc-variant="marker-columns" class="site-section-spacing scroll-mt-24 md:scroll-mt-28" style="background-color: {{ $wrapperBg }};">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mb-6">
            @if (!empty($section['title']))
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h2 class="text-3xl md:text-4xl font-extrabold text-pretty" style="color: {{ $textOnWrapper }};"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @if (!empty($section['intro']))
                    <div class="mt-3 text-base md:text-lg" style="color: {{ $mutedOnWrapper }};"
                         {!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
                @endif
            @else
                @if ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
                @endif
            @endif
        </div>
        @if ($items !== [])
            <div class="md:columns-2 md:gap-x-16 mt-8">
                @foreach ($items as $i => $item)
                    <div class="break-inside-avoid grid grid-cols-[1.6rem_1fr] gap-3 py-5"
                         style="border-bottom: 1px solid color-mix(in oklab, {{ $hairlineBase }} {{ ($i + 1) % 3 === 0 ? '28' : '12' }}%, transparent);">
                        <span class="text-lg font-bold leading-6" style="color: {{ $accentOnWrapper }};">+</span>
                        <div>
                            @if ($emitMarkers)
                                <button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>
                            @endif
                            <h3 class="text-base md:text-lg font-bold" style="color: {{ $textOnWrapper }};"
                                {!! $editor("items.{$i}.title", 'plain') !!}>@if ($href = $resolveItemHrefForVariants($item))<a href="{{ $href }}" class="hover:underline" style="color: inherit;">{{ $item['title'] ?? '' }}</a>@else{{ $item['title'] ?? '' }}@endif</h3>
                            <div class="mt-1 text-sm md:text-base leading-relaxed" style="color: {{ $mutedOnWrapper }};"
                                 {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
