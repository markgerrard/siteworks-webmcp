
@php
    // When __surface is contrast the wrapper is a different background
    // from its neighbours, so full site-section-spacing applies (the
    // background change absorbs the seam).
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface-alt)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text-on-alt)';
    $mutedOnWrapper = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted-on-alt)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
@endphp
<div class="site-section-spacing" style="background-color: {{ $wrapperBg }};">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="text-center mb-16">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-4 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
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

        @if (!empty($section['items']))
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
                @foreach ($section['items'] as $i => $item)
                    <div class="relative text-center">
                        <div class="w-20 h-20 rounded-full mx-auto mb-6 flex items-center justify-center text-2xl font-bold shadow-lg"
                             style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                            <span{!! $editor("items.{$i}.step", 'plain') !!}>{{ $item['step'] ?? ($i + 1) }}</span>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold mb-3 leading-snug"
                            style="color: {{ $textOnWrapper }};"
                            {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                        <div class="text-base leading-relaxed prose prose-base max-w-none"
                             style="color: {{ $mutedOnWrapper }};"
                             {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
