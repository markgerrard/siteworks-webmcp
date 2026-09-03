@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $candidates = (isset($site) && isset($page))
        ? app(\App\Services\Site\SimilarProjectsSelector::class)->forPage($site, $page)
        : collect();

    $svcVariant = 'classic';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.similar_projects.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.similar_projects.classic';
    }
@endphp
@if ($candidates->isNotEmpty())
    @include($svcVariantView)
@endif
