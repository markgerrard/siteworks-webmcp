@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Traditional-craftsman flavour: horizontal strip of portfolio thumbnails.
    // profile['portfolio_images'] is a forward-looking field — the profiler
    // doesn't currently emit it. Until it does, this section silently
    // renders nothing, which matches the phase-09 visibility-gate rule.
    $images = $profile['portfolio_images'] ?? null;
    $images = is_array($images)
        ? array_values(array_filter(
            array_map(fn ($i) => is_string($i) ? trim($i) : null, $images),
            fn ($u) => $u !== null && preg_match('#^https?://#i', $u),
        ))
        : [];
    $eyebrow = $section['eyebrow'] ?? 'Our work';
@endphp

@if ($images !== [])
    @php
        // When __surface is contrast the wrapper is a different background
        // from its neighbours, so full site-section-spacing applies (the
        // background change absorbs the seam).
        $isContrast = ($section['__surface'] ?? null) === 'contrast';
        $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface)';
        $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text)';
        $mutedOnWrapper = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted)';
        $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text)';
    @endphp
    <div class="{{ $isContrast ? 'site-section-spacing' : 'py-12 md:py-16' }}" style="background-color: {{ $wrapperBg }};">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-8">
                @if (empty($section['__suppress_eyebrow']))
                    <p class="text-xs font-bold tracking-widest uppercase mb-2" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h3 class="text-2xl md:text-3xl font-extrabold" style="color: {{ $textOnWrapper }};">
                    {{ $section['title'] ?? 'Recent projects' }}
                </h3>
                @if (!empty($section['intro']))
                    <p class="mt-2 text-base max-w-2xl mx-auto" style="color: {{ $mutedOnWrapper }};">
                        {{ $section['intro'] }}
                    </p>
                @endif
            </div>
            @php
                $portfolioContext = trim((string) ($section['title'] ?? $profile['name'] ?? 'Recent project'));
            @endphp
            <div class="grid gap-3 sm:gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
                @foreach (array_slice($images, 0, 8) as $i => $url)
                    <div class="aspect-square overflow-hidden"
                         style="border: 1px solid var(--color-border); border-radius: var(--radius-card);">
                        <img src="{{ $url }}" alt="{{ $portfolioContext }} — image {{ $i + 1 }}"
                             class="w-full h-full object-cover transition-transform hover:scale-105"
                             loading="lazy" />
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
