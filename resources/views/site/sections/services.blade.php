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
    $eyebrow = $section['eyebrow'] ?? 'What We Do';

    // Shared item-href resolver for NEW variants (numbered-rows,
    // marker-columns): source_service via the pipeline slugger first, then
    // an exact/prefix title-slug match. classic/photo-cards keep their own
    // internal richer copy (word-subset fallback) untouched — byte-identity
    // over DRY; consolidation is a recorded cleanup.
    $site = $site ?? null;
    $profile = $profile ?? [];
    $pagesBySlug = $pagesBySlug ?? [];
    $resolveItemHrefForVariants = function ($item) use ($site, $profile, $pagesBySlug) {
        $location = $site?->location ?? '';
        $scope = is_string($profile['geo']['scope'] ?? null) ? $profile['geo']['scope'] : 'local';
        $source = $item['source_service'] ?? null;
        if (is_string($source) && trim($source) !== '') {
            $slug = app(\App\Services\Site\ServicePageSlugger::class)->makeSlug($source, $location, $scope);
            if (isset($pagesBySlug[$slug])) {
                return $pagesBySlug[$slug];
            }
        }
        $base = \Illuminate\Support\Str::slug($item['title'] ?? '');
        if ($base === '') {
            return null;
        }
        if (isset($pagesBySlug[$base])) {
            return $pagesBySlug[$base];
        }
        foreach ($pagesBySlug as $slug => $href) {
            if (str_starts_with($slug, $base.'-')) {
                return $href;
            }
        }
        return null;
    };

    /**
     * Defensive icon rendering.
     * - http(s) URL → render <img>
     * - integer (media id) → skip (no resolver in template layer; treated as no icon)
     * - icon-name string → render via Lucide <i data-lucide> tag
     * - anything else → skip
     */
    $renderIcon = function ($icon, $classes = 'w-6 h-6') {
        if (empty($icon)) {
            return '';
        }
        if (is_int($icon) || (is_string($icon) && ctype_digit((string) $icon))) {
            return ''; // media id — can't resolve in template layer
        }
        if (is_string($icon) && preg_match('/^https?:\/\//i', $icon)) {
            return '<img src="'.e($icon).'" alt="" class="'.$classes.' object-contain">';
        }
        if (is_string($icon) && preg_match('/^[a-z][a-z0-9-]*$/i', $icon)) {
            // Looks like a Lucide icon name
            return '<i data-lucide="'.e($icon).'" class="'.$classes.'"></i>';
        }
        return '';
    };
@endphp

@php
    $svcVariant = 'classic';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.services.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.services.classic';
    }
@endphp
@include($svcVariantView)
