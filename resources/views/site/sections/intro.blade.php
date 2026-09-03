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

    // Supporting detail image for the service page. Mirrors hero.blade.php's
    // watermark preference: when the preview watermark flag is on and a
    // watermarked variant exists, use it; otherwise fall back to the raw URL.
    $introImg = $introImageUrl ?? null;
    if (is_array($introImg)) {
        $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);
        $introImg = ($watermarkOn && ! empty($introImg['watermark_url']))
            ? $introImg['watermark_url']
            : ($introImg['url'] ?? null);
    }
    $eyebrow = $section['eyebrow'] ?? 'About This Service';
@endphp

@php
    $svcVariant = 'classic';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.intro.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.intro.classic';
    }
@endphp
@include($svcVariantView)
