@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $areas = is_array($section['areas'] ?? null) ? array_values($section['areas']) : [];
    $areas = array_slice(array_filter($areas, 'is_string'), 0, 6);

    // Resolve "Check if we cover you" href layout-aware. On multi-page
    // sites #contact anchors on the current (home) page which has no
    // contact section — the link goes nowhere. Treat '#contact' as
    // "resolve via pagesBySlug" → /contact on multi_page, #contact on
    // one-page stacked. Explicit non-magic cta_url values still win.
    $rawCtaUrl = $section['cta_url'] ?? null;
    $ctaHref = ($rawCtaUrl === null || $rawCtaUrl === '#contact')
        ? ($pagesBySlug['contact'] ?? '#contact')
        : $rawCtaUrl;

    // Image source: prefer the page's intro image (a different shot from
    // the hero, generated alongside it via /generate-hero) so the service
    // area section doesn't reuse the same photo as the hero. Falls back
    // to the hero on pages where no intro exists. Mirrors hero.blade.php
    // / features.blade.php resolution for watermark.
    $bgImage = $introImageUrl ?? $heroImageUrl ?? null;
    if (is_array($bgImage)) {
        $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);
        $bgImage = ($watermarkOn && ! empty($bgImage['watermark_url']))
            ? $bgImage['watermark_url']
            : ($bgImage['url'] ?? null);
    }
    $eyebrow = $section['eyebrow'] ?? 'Service Area';

    // Optional card placement (section.panel_side): 'right' floats the info
    // card over the right half so photography whose subject sits left keeps
    // its subject visible. Anything else = the default left placement.
    $panelRight = ($section['panel_side'] ?? null) === 'right';
@endphp

{{-- service_area_card: TAZO-style "local trust" section — a wide photo
     with a floating info card overlapping the left edge, listing the
     areas covered. Only renders for local/regional scope sites (AI
     gates this at prompt time via profile.geo.scope). --}}
<div class="site-section-spacing" style="background-color: var(--color-surface);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden"
             style="border-radius: var(--radius-card); min-height: 440px; background-color: var(--color-surface-alt);">

            @if (!empty($bgImage))
                {{-- The page's intro image (different shot from the hero)
                     when available; falls back to a zoomed-in hero on
                     pages without an intro slot. --}}
                <div class="absolute inset-0"
                     role="img"
                     aria-label="{{ $section['title'] ?? 'Service area' }}"
                     style="background-image: url('{{ $bgImage }}'); background-size: 150%; background-position: center; background-repeat: no-repeat;"></div>
                {{-- Dim the image from the card's side so it always has
                     readable contrast even on bright imagery. --}}
                <div class="absolute inset-0 pointer-events-none"
                     style="background: linear-gradient({{ $panelRight ? '270deg' : '90deg' }}, rgba(0,0,0,0.65) 0%, rgba(0,0,0,0.35) 45%, rgba(0,0,0,0) 70%);"></div>
            @endif

            <div class="relative grid grid-cols-1 lg:grid-cols-12 h-full min-h-[440px]">
                @if ($panelRight)
                    {{-- Spacer pushes the card into columns 7-12 so the
                         photo's left-side subject stays visible. --}}
                    <div class="hidden lg:block lg:col-span-6" aria-hidden="true"></div>
                @endif
                <div class="lg:col-span-6 p-6 sm:p-10 flex items-center"@if ($panelRight) data-panel-side="right" @endif>
                    <div class="w-full p-6 sm:p-8"
                         style="background-color: var(--color-band, #0f172a); border-radius: var(--radius-card); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.45);">
                        <span class="text-xs font-bold tracking-widest uppercase mb-3 block"
                              style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>
                            {{ $eyebrow }}
                        </span>
                        @if (!empty($section['title']))
                            <h2 class="text-2xl md:text-3xl font-extrabold mb-3 leading-tight"
                                style="color: var(--color-text-on-band, #ffffff);"
                                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                        @else
                            @if ($emitMarkers)
                                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                            @endif
                        @endif
                        @if (!empty($section['intro']))
                            <p class="text-sm md:text-base mb-5 leading-relaxed"
                               style="color: var(--color-text-on-band, #ffffff); opacity: 0.85;"
                               {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
                        @else
                            @if ($emitMarkers)
                                <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
                            @endif
                        @endif
                        @if ($areas !== [])
                            <ul class="grid grid-cols-2 gap-x-4 gap-y-2 mb-6">
                                @foreach ($areas as $i => $area)
                                    <li class="flex items-center gap-2"
                                        style="color: var(--color-text-on-band, #ffffff);">
                                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"
                                             style="color: var(--brand-accent);">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        <span class="text-sm font-medium"
                                              {!! $editor("areas.{$i}", 'plain') !!}>{{ $area }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (!empty($section['cta_label']))
                            <a href="{{ $ctaHref }}"
                               class="inline-flex items-center gap-2 font-bold px-6 py-3 shadow-md transition-all hover:shadow-lg hover:brightness-110"
                               style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                                <span{!! $editor('cta_label', 'plain') !!}>{{ $section['cta_label'] }}</span>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                            </a>
                            @if ($emitMarkers)
                                <button type="button" class="hidden"{!! $editor('cta_url', 'url') !!}></button>
                            @endif
                        @else
                            @if ($emitMarkers)
                                <span class="hidden"{!! $editor('cta_label', 'plain') !!}></span>
                                <span class="hidden"{!! $editor('cta_url', 'url') !!}></span>
                            @endif
                        @endif
                    </div>
                </div>
                @unless ($panelRight)
                    {{-- Trailing spacer completes the left-panel layout; in
                         right mode the leading spacer already fills cols 1-6
                         (both spacers = 18 cols → wrap). --}}
                    <div class="hidden lg:block lg:col-span-6"></div>
                @endunless
            </div>
        </div>
    </div>
</div>
