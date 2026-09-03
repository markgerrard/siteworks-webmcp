@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $itemIds = $section['item_ids'] ?? [];
    $items = collect($itemIds)
        ->map(fn ($id) => ($itemsById ?? collect())->get($id))
        ->filter()
        ->values();
    $vocab = $projectVocab ?? null;
    // Vocab's resolver returns the example or marketing heading based on
    // honest-framing + item sources. It wins over any section-level title
    // — once honest framing is on, AI-seeded "Recent Work" strings must
    // become "Example Projects" without requiring regeneration.
    $heading = $vocab
        ? $vocab->galleryHeading($items)
        : ($section['title'] ?? 'Recent Work');

    $linkDetailPages = ($section['__options']['link_detail_pages'] ?? false) === true;
    $pagesBySlug = $pagesBySlug ?? [];
    $detailHrefFor = function ($item) use ($linkDetailPages, $pagesBySlug): ?string {
        if (! $linkDetailPages) {
            return null;
        }
        $detail = $item->detailPage ?? null;
        if ($detail === null
            || $detail->archived_at !== null
            || $detail->status !== \App\Enums\PageStatus::Published) {
            return null;
        }
        // Substance gate: only offer the click-through
        // when the detail page carries MORE than the tile already shows —
        // an About body or supplementary gallery images. A bare scaffold
        // adds nothing worth a click.
        $detailSections = $detail->content_data['sections'] ?? [];
        $hasSubstance = false;
        foreach ($detailSections as $detailSection) {
            $type = $detailSection['type'] ?? null;
            if ($type === 'project_about' && trim((string) ($detailSection['body'] ?? '')) !== '') {
                $hasSubstance = true;
                break;
            }
            if ($type === 'project_photo_essay' && count((array) ($detailSection['image_ids'] ?? [])) > 0) {
                $hasSubstance = true;
                break;
            }
        }
        if (! $hasSubstance) {
            return null;
        }
        $pageType = $detail->page_type;
        if (! is_string($pageType) || $pageType === '') {
            return null;
        }

        return $pagesBySlug[$pageType] ?? '/'.$pageType;
    };
    $childPages = (isset($site) && isset($page))
        ? app(\App\Services\Site\ChildPageEnumerator::class)->forPage($site, $page)
        : collect();
@endphp

@php
    $svcVariant = 'classic';
    if (is_string($section['variant'] ?? null) && preg_match('/^[a-z0-9-]+$/', $section['variant'])) {
        $svcVariant = $section['variant'];
    }
    $svcVariantView = "site.sections.variants.project_gallery.{$svcVariant}";
    if (! view()->exists($svcVariantView)) {
        $svcVariantView = 'site.sections.variants.project_gallery.classic';
    }
@endphp
@include($svcVariantView)
