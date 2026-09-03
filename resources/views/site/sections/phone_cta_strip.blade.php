@php
    // Oversized phone number as the primary action. Uses the first phone
    // on profile; if missing, this section renders nothing — no point in
    // a "call us now" strip without a number. Title/subtitle are
    // populated by the injection layer from Archetype::phoneCtaCopy()
    // (or by the editor / RegenerateProjectsCtaCopyJob). The neutral
    // fallback below only fires when this section renders without
    // either; emergency-archetype framing must come from the archetype
    // resolution path, never from a blade default.
    $phone = $profile['contact']['phones'][0] ?? null;
    $phone = is_string($phone) ? trim($phone) : null;

    $heading = $section['title'] ?? 'Get in touch';
    $subline = $section['subtitle'] ?? '';
@endphp

@if ($phone !== null && $phone !== '')
    {{-- Strip background uses --color-surface-alt rather than --brand-primary
         so it doesn't blast a saturated brand colour across the page on
         brands whose primary is bright (cyan, yellow), and doesn't disappear
         into the surface on brands whose primary is near-black. The brand
         identity carries via the phone number + icon coloured with
         --color-accent-text-on-alt. --}}
    <div class="py-10 md:py-12{{ \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !== '' ? ' relative overflow-hidden' : '' }}" style="background-color: var(--color-surface-alt);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        <div class="site-shell-container px-4 sm:px-6 lg:px-8 text-center">
            <p class="text-xs md:text-sm font-bold uppercase tracking-widest mb-2"
               style="color: var(--color-text-muted-on-alt);">
                {{ $heading }}
            </p>
            <a href="tel:{{ preg_replace('/[^+\d]/', '', $phone) }}"
               class="inline-flex items-center justify-center gap-3 text-4xl md:text-5xl lg:text-6xl font-extrabold leading-none hover:brightness-110 transition-all"
               style="color: var(--color-accent-text-on-alt);">
                <svg class="w-8 h-8 md:w-10 md:h-10" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                {{ $phone }}
            </a>
            @if ($subline !== '')
                <p class="mt-3 text-sm md:text-base"
                   style="color: var(--color-text-muted-on-alt);">
                    {{ $subline }}
                </p>
            @endif
        </div>
    </div>
@endif
