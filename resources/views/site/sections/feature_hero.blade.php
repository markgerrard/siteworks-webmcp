@php
    $editor = function ($field, $type, $valueDoc = null) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        $attrs = ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        if ($type === 'rich' && $valueDoc !== null) {
            $attrs .= ' data-editable-doc="'.e(json_encode($valueDoc)).'"';
        }

        return $attrs;
    };

    $eyebrow       = $section['eyebrow'] ?? null;
    $title         = $section['title'] ?? null;
    $subtitle      = $section['subtitle'] ?? null;
    $bullets       = is_array($section['bullets'] ?? null) ? array_values($section['bullets']) : [];
    $ctaLabel      = $section['cta_label'] ?? null;
    $ctaUrl        = $section['cta_url'] ?? '#';
    $secondaryLabel = $section['secondary_cta_label'] ?? null;
    $secondaryUrl  = $section['secondary_cta_url'] ?? '#';
    $screenshotUrl = $section['screenshot_url'] ?? null;
    $screenshotAlt = $section['screenshot_alt'] ?? ($title ?? 'Product screenshot');
@endphp

@php
    // When no screenshot is set, render single-column centered text — useful as
    // a text-only "story header" on pages where the two-column visual layout
    // would just show an empty placeholder.
    $hasMedia = ! empty($screenshotUrl);
    $gridClasses = $hasMedia
        ? 'grid grid-cols-1 lg:grid-cols-2 gap-12 items-center'
        : 'max-w-3xl mx-auto text-center';
    $copyClasses = $hasMedia ? '' : 'mx-auto';
@endphp
<div class="site-section-spacing" style="background-color: var(--color-surface-alt);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="{{ $gridClasses }}">

            {{-- Left: copy column --}}
            <div class="{{ $copyClasses }}">
                @if (!empty($eyebrow))
                    <span class="text-sm font-semibold uppercase tracking-wider mb-4 block"
                          style="color: var(--brand-accent);"
                          {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif

                @if (!empty($title))
                    <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold leading-tight text-balance"
                        style="color: var(--color-primary);"
                        {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif

                @if (!empty($subtitle))
                    <p class="mt-5 text-lg md:text-xl max-w-prose mx-auto leading-relaxed"
                       style="color: var(--color-text-muted);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $subtitle }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                @endif

                @if (!empty($bullets))
                    <ul class="mt-6 space-y-2 {{ $hasMedia ? '' : 'inline-block text-left' }}" aria-label="Key capabilities">
                        @foreach ($bullets as $bullet)
                            <li class="flex items-start gap-3">
                                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true" style="color: var(--brand-accent);">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span class="text-base leading-snug" style="color: var(--color-text);">{{ $bullet }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (!empty($ctaLabel))
                    <div class="mt-8 flex flex-wrap gap-4 items-center">
                        <a href="{{ $ctaUrl }}"
                           class="inline-flex items-center gap-2 font-semibold px-7 py-3.5 shadow-md transition-all hover:shadow-lg hover:brightness-110"
                           style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                            <span{!! $editor('cta_label', 'plain') !!}>{{ $ctaLabel }}</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </a>
                        @if ($emitMarkers)
                            <button type="button" class="hidden"{!! $editor('cta_url', 'url') !!}></button>
                        @endif

                        @if (!empty($secondaryLabel))
                            <a href="{{ $secondaryUrl }}"
                               class="inline-flex items-center gap-2 font-semibold px-7 py-3.5 border-2 transition-all hover:bg-white/10"
                               style="color: var(--color-primary); border-color: var(--color-border); border-radius: var(--radius-button);">
                                <span{!! $editor('secondary_cta_label', 'plain') !!}>{{ $secondaryLabel }}</span>
                            </a>
                            @if ($emitMarkers)
                                <button type="button" class="hidden"{!! $editor('secondary_cta_url', 'url') !!}></button>
                            @endif
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor('secondary_cta_label', 'plain') !!}></span>
                            <span class="hidden"{!! $editor('secondary_cta_url', 'url') !!}></span>
                        @endif
                    </div>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('cta_label', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('cta_url', 'url') !!}></span>
                @endif
            </div>

            {{-- Right: visual column — only rendered when a screenshot is set,
                 otherwise the section collapses to a centered text-only intro. --}}
            @if ($hasMedia)
                <div class="flex items-center justify-center lg:justify-end">
                    <div class="relative w-full max-w-xl transition-transform duration-500 hover:rotate-0"
                         style="transform: rotate(1deg);">
                        <img src="{{ $screenshotUrl }}"
                             alt="{{ $screenshotAlt }}"
                             loading="eager"
                             class="w-full h-auto rounded-xl shadow-2xl"
                             style="border: 1px solid var(--color-border);">
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
