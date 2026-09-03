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
    $richHtml = fn ($val) => app(\App\Services\Site\RichTextRenderer::class)->renderValue($val);

    $benefits = is_array($section['benefits'] ?? null) ? array_values($section['benefits']) : [];
    $extraFields = is_array($section['extra_fields'] ?? null) ? array_values($section['extra_fields']) : [];
    $messageFieldMigrated = (bool) ($section['message_field_migrated'] ?? false);

    $submitLabel = $section['submit_label'] ?? 'Send Message';

    // Allowed field types — anything else collapses to text.
    // `date` renders as a text input + Flatpickr so we get a consistent
    // calendar UX on every browser regardless of native date-input styling.
    $allowedTypes = ['text', 'tel', 'date', 'select', 'radio', 'textarea'];

    // Hero banner wash behind the band — same resolution pattern as
    // hero.blade.php + features.blade.php, 0.08 opacity to match the
    // What's Included band.
    $bgHero = $heroImageUrl ?? null;
    if (is_array($bgHero)) {
        $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);
        $bgHero = ($watermarkOn && ! empty($bgHero['watermark_url']))
            ? $bgHero['watermark_url']
            : ($bgHero['url'] ?? null);
    }
    $eyebrow = $section['eyebrow'] ?? 'Get in touch';

    $formMarker = ($emitFormMarkers ?? false)
        ? ' data-form-editable="page.'.e($pageId).'.section.'.e($sectionIndex).'" data-form-kind="'.e($section['type'] ?? '').'"'
        : '';
    $enquireId = ! empty($enquireAnchor) ? ' id="enquire"' : '';
    $enquireClass = ! empty($enquireAnchor) ? ' scroll-mt-24 md:scroll-mt-28' : '';
    // $leadFormNested is set by a variant that re-enters this template for the identity path (image-backed without an image); it suppresses the nested Flatpickr script so a page ships one copy.
    // The raw PHP tags around the script below are deliberate: a Blade directive cannot follow @endif without a word boundary.
    // The blank line after the identity @include's closing ]) is load-bearing: @include compiles to a PHP close-tag which swallows one newline; do not remove it (D0-pinned).
    $leadFormVariant = is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant']) === 1 ? $section['variant'] : null;
@endphp@if ($leadFormVariant !== null && view()->exists('site.sections.variants.lead_form.'.$leadFormVariant))@include('site.sections.variants.lead_form.'.$leadFormVariant)@else

<div{!! $enquireId !!} class="site-section-spacing relative overflow-hidden{{ $enquireClass }}"
     style="background-color: var(--color-band, #0f172a);
            background-image:
                radial-gradient(ellipse 60% 50% at 15% 10%, color-mix(in oklab, var(--color-primary, #0f172a) 35%, transparent) 0%, transparent 60%),
                radial-gradient(ellipse 55% 45% at 85% 90%, color-mix(in oklab, var(--color-accent, #0f172a) 30%, transparent) 0%, transparent 55%),
                radial-gradient(ellipse 80% 60% at 50% 40%, color-mix(in oklab, var(--color-primary, #0f172a) 12%, transparent) 0%, transparent 70%);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}

    <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-start">

            {{-- LEFT: conversion copy + benefits --}}
            <div class="lg:col-span-6" style="color: var(--color-text-on-band, #ffffff);">
                <span class="text-sm font-bold tracking-widest uppercase mb-4 block opacity-80" {!! $editor('eyebrow', 'plain') !!}>
                    {{ $eyebrow }}
                </span>
                @if (!empty($section['title']))
                    <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold mb-5 leading-tight text-balance"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                @endif
                @if (!empty($section['intro']))
                    <div class="text-lg md:text-xl leading-relaxed mb-8 opacity-90 max-w-xl"
                         {!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
                    @endif
                @endif

                @if ($benefits !== [])
                    <ul class="space-y-3 max-w-md">
                        @foreach ($benefits as $i => $benefit)
                            <li class="flex items-center gap-3">
                                <span class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center"
                                      style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                                <span class="text-base md:text-lg font-medium"
                                      {!! $editor("benefits.{$i}", 'plain') !!}>{{ $benefit }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- RIGHT: floating form card --}}
            <div class="lg:col-span-6">
                @include('site.partials.lead-form-core', [
                    'fields' => $extraFields,
                    'messageFieldMigrated' => $messageFieldMigrated,
                    'formMarker' => $formMarker,
                    'allowedTypes' => $allowedTypes,
                    'formId' => "lf-{$pageId}-{$sectionIndex}",
                    'site' => $site ?? null,
                    'pageId' => $pageId, 'sectionIndex' => $sectionIndex, 'pageType' => $pageType ?? '',
                    'emitMarkers' => $emitMarkers,
                    'editor' => $editor,
                    'submitLabel' => $submitLabel,
                    'cardClass' => 'bg-white p-7 md:p-9 border-t-4',
                    'cardStyle' => 'border-top-color: var(--brand-accent); border-radius: var(--radius-card); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);',
                    'chrome' => ['input_style' => null, 'radio_style' => null, 'submit_style' => null, 'surface' => null],
                    'layout' => 'stacked',
                ])

            </div>
        </div>
    </div>
</div>
@endif<?php if (empty($leadFormNested)): ?><script>
    (function () {
        function initDatePickers() {
            if (typeof flatpickr === 'undefined') return;
            document.querySelectorAll('input[data-flatpickr]:not(.flatpickr-input)').forEach(function (el) {
                flatpickr(el, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y', minDate: 'today' });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initDatePickers);
        } else {
            initDatePickers();
        }
        // Init again once flatpickr finishes loading (deferred script race).
        window.addEventListener('load', initDatePickers);
    })();
</script>
<?php endif; ?>
