@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };
    $ctaVariant = $section['variant'] ?? null;
    $isAccentBand = $ctaVariant === 'accent-band';
    $isMarqueeBand = $ctaVariant === 'marquee-band' && ($section['__options']['marquee_band'] ?? false) === true;
    $isSoftBrandSection = ($theme['brand_section_scheme'] ?? null) === 'soft';
    $softBrandSectionAttribute = $isSoftBrandSection && ! $isAccentBand ? ' data-brand-section-scheme="soft"' : '';
    $softPatternAttribute = $isSoftBrandSection && ! $isAccentBand ? ' style="filter: invert(1);"' : '';
@endphp@if ($isMarqueeBand)
<div class="site-section-spacing relative overflow-hidden cta-marquee" style="background-color: {{ $isSoftBrandSection ? 'var(--color-brand-section-surface)' : 'var(--brand-primary)' }};" data-cta-variant="marquee-band"{!! $softBrandSectionAttribute !!}>
    <style>
        .cta-marquee-viewport { overflow: hidden; }
        .cta-marquee-track {
            display: flex;
            width: max-content;
            animation: cta-marquee-scroll 32s linear infinite;
        }
        .cta-marquee-item {
            margin: 0;
            padding-inline-end: 0.35em;
            font-family: var(--font-display, inherit);
            font-size: clamp(4.5rem, 14vw, 12rem);
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 0.85;
            white-space: nowrap;
            color: {{ $isSoftBrandSection ? 'var(--color-brand-section-ink)' : 'var(--color-text-on-primary, #ffffff)' }};
        }
        @keyframes cta-marquee-scroll {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            .cta-marquee-track {
                animation: none;
                transform: none;
                width: 100%;
                justify-content: center;
            }
            /* Static state must show the FULL title: the animated clamp +
               nowrap would overflow a min-content flex item and the hidden
               viewport clips both ends. Wrap at a smaller
               static scale instead. */
            .cta-marquee-item {
                white-space: normal;
                overflow-wrap: break-word;
                text-align: center;
                max-width: 100%;
                font-size: clamp(2.25rem, 7vw, 5.5rem);
                line-height: 1.05;
            }
            .cta-marquee-item:not(:first-child) {
                display: none;
            }
        }
    </style>
    <div class="cta-marquee-viewport">
        <div class="cta-marquee-track">
            @if (!empty($section['title']))
                <h2 class="cta-marquee-item"{!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                <span class="cta-marquee-item" aria-hidden="true">{{ $section['title'] }}</span>
            @else
                @if ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif
            @endif
        </div>
    </div>
    <div class="relative site-shell-container px-4 sm:px-6 lg:px-8 text-center" style="max-width: min(var(--container-width), 64rem);">
        @if (!empty($section['body']))
            <p class="text-lg md:text-xl mb-10 max-w-2xl mx-auto leading-relaxed opacity-80"
               style="color: {{ $isSoftBrandSection ? 'var(--color-brand-section-muted-ink)' : 'var(--color-text-on-primary, #ffffff)' }};"
               {!! $editor('body', 'plain') !!}>{{ $section['body'] }}</p>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('body', 'plain') !!}></span>
            @endif
        @endif
        @if (!empty($section['button_label']))
            @php
                $rawBtn = $section['button_url'] ?? null;
                $resolvedBtn = ($rawBtn === null || $rawBtn === '#contact')
                    ? ($pagesBySlug['contact'] ?? '#contact')
                    : $rawBtn;
            @endphp
            <a href="{{ $resolvedBtn }}"
               class="inline-flex items-center gap-2 font-bold px-10 py-4 rounded-md shadow-xl text-lg transition-all hover:shadow-2xl hover:scale-105"
               style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                <span{!! $editor('button_label', 'plain') !!}>{{ $section['button_label'] }}</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
            @if ($emitMarkers)
                <button type="button" class="hidden"{!! $editor('button_url', 'url') !!}></button>
            @endif
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('button_label', 'plain') !!}></span>
                <span class="hidden"{!! $editor('button_url', 'url') !!}></span>
            @endif
        @endif
    </div>
</div>
@else

<div class="site-section-spacing relative overflow-hidden" style="background-color: {{ $isAccentBand ? 'var(--brand-accent)' : ($isSoftBrandSection ? 'var(--color-brand-section-surface)' : 'var(--brand-primary)') }};"@if ($ctaVariant) data-cta-variant="{{ $ctaVariant }}" @endif{!! $softBrandSectionAttribute !!}>
    {!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, true, $site ?? null, $isSoftBrandSection && ! $isAccentBand) !!}
    @if (! $isAccentBand && ! $isSoftBrandSection)
        <div class="absolute inset-0" style="background: linear-gradient(to right, rgba(0,0,0,0.25), rgba(0,0,0,0.05));"></div>
    @endif
    <div class="relative site-shell-container px-4 sm:px-6 lg:px-8 text-center" style="max-width: min(var(--container-width), 64rem);">
        @if (!empty($section['title']))
            {{-- No AccentWordRenderer wrap on the cta band: on a primary-
                 coloured background, the accent-colour highlight (which
                 is usually a sibling tint of primary) would read as
                 cyan-on-cyan and the word disappears. The band itself is
                 already the "highlight" — monotone headline is correct. --}}
            <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold mb-4 leading-tight text-pretty"
                style="color: {{ $isAccentBand ? 'var(--color-text-on-accent, #ffffff)' : ($isSoftBrandSection ? 'var(--color-brand-section-ink)' : 'var(--color-text-on-primary, #ffffff)') }};"
                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif
        @if (!empty($section['body']))
            <p class="text-lg md:text-xl mb-10 max-w-2xl mx-auto leading-relaxed opacity-80"
               style="color: {{ $isAccentBand ? 'var(--color-text-on-accent, #ffffff)' : ($isSoftBrandSection ? 'var(--color-brand-section-muted-ink)' : 'var(--color-text-on-primary, #ffffff)') }};"
               {!! $editor('body', 'plain') !!}>{{ $section['body'] }}</p>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('body', 'plain') !!}></span>
            @endif
        @endif
        @if (!empty($section['button_label']))
            @php
                // Resolve layout-aware — same magic as cta_band / service_area_card.
                $rawBtn = $section['button_url'] ?? null;
                $resolvedBtn = ($rawBtn === null || $rawBtn === '#contact')
                    ? ($pagesBySlug['contact'] ?? '#contact')
                    : $rawBtn;
            @endphp
            <a href="{{ $resolvedBtn }}"
               class="inline-flex items-center gap-2 font-bold px-10 py-4 rounded-md shadow-xl text-lg transition-all hover:shadow-2xl hover:scale-105"
               style="background-color: {{ $isAccentBand ? 'var(--brand-primary)' : 'var(--brand-accent)' }}; color: {{ $isAccentBand ? 'var(--color-text-on-primary, #ffffff)' : 'var(--color-text-on-accent, #ffffff)' }}; border-radius: var(--radius-button);@if ($isAccentBand) box-shadow: 0 0 0 1px var(--color-text-on-accent, #ffffff), 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);@endif">
                <span{!! $editor('button_label', 'plain') !!}>{{ $section['button_label'] }}</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
            @if ($emitMarkers)
                <button type="button" class="hidden"{!! $editor('button_url', 'url') !!}></button>
            @endif
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('button_label', 'plain') !!}></span>
                <span class="hidden"{!! $editor('button_url', 'url') !!}></span>
            @endif
        @endif
    </div>
</div>
@endif
