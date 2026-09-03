@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $items = array_values($section['items'] ?? []);
    $eyebrow = $section['eyebrow'] ?? 'Our Values';
@endphp

@php
    $svcVariant = 'classic';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.values.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.values.classic';
    }

    // Unwrap slot images once here so statements / ledger don't each
    // reimplement watermark preference. Band prefers the dedicated slot
    // and falls back to the page hero (today's statements/ledger source).
    $profile = $profile ?? [];
    $slotImageUrl = function ($image) use ($profile) {
        if (is_array($image)) {
            $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);

            return ($watermarkOn && ! empty($image['watermark_url']))
                ? $image['watermark_url']
                : ($image['url'] ?? null);
        }

        return $image;
    };
    $heroImg = $slotImageUrl($heroImageUrl ?? null);
    $bandImg = $slotImageUrl($bandImageUrl ?? null) ?? $heroImg;
@endphp
@include($svcVariantView)
