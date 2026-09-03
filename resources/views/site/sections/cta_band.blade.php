@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Resolve the "Get a quote" href layout-aware. ArchetypeComposer
    // injects cta_url='#contact' as a default when it creates the
    // cta_band — on a multi-page site that anchors on the current page
    // (e.g. /about#contact) which doesn't exist. Treat '#contact' as
    // "resolve to the contact page" and pull the real URL from
    // pagesBySlug (→ /contact on multi-page, #contact on one-page
    // stacked layout). Explicit non-magic cta_url values still win.
    $rawCtaUrl = $section['cta_url'] ?? null;
    $resolvedCtaUrl = ($rawCtaUrl === null || $rawCtaUrl === '#contact')
        ? ($pagesBySlug['contact'] ?? '#contact')
        : $rawCtaUrl;
@endphp

{{-- cta_band: a softer, About-page-appropriate sign-post to the contact
     page. Deliberately less intense than cta.blade.php (which owns the
     home/service page's primary-colour band) so it reads as "nudge"
     rather than "hard sell" on a trust-building page like About. --}}
<div class="site-section-spacing relative overflow-hidden"
     style="background-color: var(--color-surface-alt);
            background-image:
                radial-gradient(ellipse 60% 55% at 50% 50%, color-mix(in oklab, var(--color-primary, transparent) 12%, transparent) 0%, transparent 70%);
            border-top: 1px solid var(--color-border);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative text-center" style="max-width: min(var(--container-width), 52rem);">
        @if (!empty($section['title']))
            <h2 class="text-3xl md:text-4xl font-extrabold mb-4 leading-tight text-balance"
                style="color: var(--color-text-on-alt);"
                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif
        @if (!empty($section['subtitle']))
            <p class="text-lg md:text-xl mb-8 max-w-2xl mx-auto leading-relaxed"
               style="color: var(--color-text-muted-on-alt);"
               {!! $editor('subtitle', 'plain') !!}>{{ $section['subtitle'] }}</p>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
            @endif
        @endif
        @if (!empty($section['cta_label']))
            <a href="{{ $resolvedCtaUrl }}"
               class="inline-flex items-center gap-2 font-bold px-8 py-3.5 shadow-md transition-all hover:shadow-lg hover:brightness-110"
               style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                <span{!! $editor('cta_label', 'plain') !!}>{{ $section['cta_label'] }}</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
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
