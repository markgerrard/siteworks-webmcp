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

    // The icon sits next to a visible <h3> title in every callsite, so it
    // is decorative for screen readers — empty alt + aria-hidden avoids
    // duplicate announcements ("title… title… body"). Sighted users still
    // see the icon as a visual cue.
    $renderIcon = function ($icon, $classes = 'w-5 h-5') {
        if (empty($icon)) {
            return '';
        }
        if (is_int($icon) || (is_string($icon) && ctype_digit((string) $icon))) {
            return '';
        }
        if (is_string($icon) && preg_match('/^https?:\/\//i', $icon)) {
            return '<img src="'.e($icon).'" alt="" aria-hidden="true" class="'.$classes.' object-contain">';
        }
        if (is_string($icon) && preg_match('/^[a-z][a-z0-9-]*$/i', $icon)) {
            return '<i data-lucide="'.e($icon).'" aria-hidden="true" class="'.$classes.'"></i>';
        }

        return '';
    };

    $items = is_array($section['items'] ?? null) ? array_values($section['items']) : [];
    $eyebrow = $section['eyebrow'] ?? "What's Included";
@endphp

@php
    $svcVariant = 'cards';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.features.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.features.cards';
    }

    // Mirror intro.blade.php: PageRenderer passes slot images as
    // ['url' => …, 'watermark_url' => …]. Unwrap to a scalar before any
    // variant interpolates it into src="". Band prefers the dedicated
    // slot and falls back to the intro image used by checklist today.
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
    $featuresImg = $slotImageUrl($introImageUrl ?? null);
    $bandImg = $slotImageUrl($bandImageUrl ?? null) ?? $featuresImg;
@endphp
@include($svcVariantView)
