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

    $eyebrow      = $section['eyebrow'] ?? null;
    $title        = $section['title'] ?? null;
    $body         = $section['body'] ?? null;
    $primaryLabel = $section['primary_cta_label'] ?? null;
    $primaryUrl   = $section['primary_cta_url'] ?? '#';
    $secondaryLabel = $section['secondary_cta_label'] ?? null;
    $secondaryUrl = $section['secondary_cta_url'] ?? '#';
@endphp

@if (!empty($title) || !empty($primaryLabel))
    {{-- Full-width brand-colour gradient band — conversion-focused bottom CTA
         for feature pages. Visually stronger than cta_band (which is a softer
         surface-alt nudge), matching the higher intent of a feature-page close. --}}
    <div class="relative overflow-hidden"
         style="background: linear-gradient(135deg, var(--brand-primary) 0%, color-mix(in oklab, var(--brand-primary) 75%, #000000) 100%);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        {{-- Subtle radial highlight for depth without a second brand colour --}}
        <div class="absolute inset-0 pointer-events-none"
             style="background: radial-gradient(ellipse 70% 60% at 30% 40%, rgba(255,255,255,0.08) 0%, transparent 65%);"></div>

        <div class="relative site-shell-container px-4 sm:px-6 lg:px-8 py-20 md:py-32">
            <div class="max-w-3xl mx-auto text-center">

                @if (!empty($eyebrow))
                    <p class="text-sm font-semibold uppercase tracking-wider mb-4"
                       style="color: rgba(255,255,255,0.70);"
                       {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif

                @if (!empty($title))
                    <h2 class="text-3xl md:text-5xl font-bold text-white mb-6 leading-tight text-balance"
                        {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                @endif

                @if (!empty($body))
                    <p class="text-lg md:text-xl mb-10 max-w-2xl mx-auto leading-relaxed"
                       style="color: rgba(255,255,255,0.90);"
                       {!! $editor('body', 'plain') !!}>{{ $body }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('body', 'plain') !!}></span>
                @endif

                @if (!empty($primaryLabel) || !empty($secondaryLabel))
                    <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">

                        @if (!empty($primaryLabel))
                            <a href="{{ $primaryUrl }}"
                               class="inline-flex items-center gap-2 font-semibold px-8 py-4 rounded-lg shadow-lg transition-all hover:shadow-xl hover:scale-[1.02]"
                               style="background-color: #ffffff; color: var(--brand-primary);">
                                <span{!! $editor('primary_cta_label', 'plain') !!}>{{ $primaryLabel }}</span>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                            </a>
                            @if ($emitMarkers)
                                <button type="button" class="hidden"{!! $editor('primary_cta_url', 'url') !!}></button>
                            @endif
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor('primary_cta_label', 'plain') !!}></span>
                            <span class="hidden"{!! $editor('primary_cta_url', 'url') !!}></span>
                        @endif

                        @if (!empty($secondaryLabel))
                            <a href="{{ $secondaryUrl }}"
                               class="inline-flex items-center gap-2 font-semibold px-8 py-4 rounded-lg border-2 border-white/60 text-white transition-all hover:bg-white/10 hover:border-white"
                               {!! $editor('secondary_cta_label', 'plain') !!}>{{ $secondaryLabel }}</a>
                            @if ($emitMarkers)
                                <button type="button" class="hidden"{!! $editor('secondary_cta_url', 'url') !!}></button>
                            @endif
                        @elseif ($emitMarkers)
                            <span class="hidden"{!! $editor('secondary_cta_label', 'plain') !!}></span>
                            <span class="hidden"{!! $editor('secondary_cta_url', 'url') !!}></span>
                        @endif

                    </div>
                @endif

            </div>
        </div>
    </div>
@endif
