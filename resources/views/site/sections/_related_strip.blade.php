@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $currentId = isset($page) ? (int) $page->id : 0;
    $sectionParams = is_array($section['params'] ?? null) ? $section['params'] : [];
    // Public / any mode with a pin: grouping params come from pinnedPages
    // (pinned revision content_data). Admin-preview/admin-edit fall back to
    // the live page row only when this page is absent from the pin set.
    $pinnedCurrent = collect($pinnedPages ?? [])->first(
        fn ($candidate) => (int) (is_array($candidate) ? ($candidate['id'] ?? 0) : ($candidate->id ?? 0)) === $currentId
    );
    if ($pinnedCurrent !== null) {
        $rawPinned = is_array($pinnedCurrent) ? ($pinnedCurrent['params'] ?? []) : ($pinnedCurrent->params ?? []);
        $pageParams = is_array($rawPinned) ? $rawPinned : [];
    } else {
        $pageContent = isset($page) && is_array($page->content_data) ? $page->content_data : [];
        $pageParams = is_array($pageContent['params'] ?? null)
            ? $pageContent['params']
            : (is_array($pageContent['meta']['params'] ?? null) ? $pageContent['meta']['params'] : []);
    }
    $currentParams = $sectionParams !== [] ? $sectionParams : $pageParams;

    $sharesParams = function (array $candidateParams) use ($currentParams): bool {
        $service = $currentParams['service'] ?? null;
        $area = $currentParams['area'] ?? null;
        $hasService = is_string($service) && $service !== '';
        $hasArea = is_string($area) && $area !== '';
        if (! $hasService && ! $hasArea) {
            return true;
        }
        if ($hasService && (string) ($candidateParams['service'] ?? '') === $service) {
            return true;
        }
        if ($hasArea && (string) ($candidateParams['area'] ?? '') === $area) {
            return true;
        }

        return false;
    };

    $links = collect($pinnedPages ?? [])
        ->filter(function ($candidate) use ($currentId, $sharesParams, $kind) {
            $id = (int) (is_array($candidate) ? ($candidate['id'] ?? 0) : ($candidate->id ?? 0));
            $candidateKind = is_array($candidate) ? ($candidate['kind'] ?? null) : ($candidate->kind ?? null);
            if ($id === 0 || $id === $currentId || $candidateKind !== $kind) {
                return false;
            }
            $params = is_array($candidate) ? ($candidate['params'] ?? []) : ($candidate->params ?? []);

            return $sharesParams(is_array($params) ? $params : []);
        })
        ->take(8)
        ->map(function ($candidate) {
            return [
                'label' => is_array($candidate) ? ($candidate['nav_label'] ?? '') : ($candidate->nav_label ?? ''),
                'href' => is_array($candidate) ? ($candidate['url'] ?? '') : ($candidate->url ?? ''),
            ];
        })
        ->filter(fn ($link) => is_string($link['label']) && $link['label'] !== '' && is_string($link['href']) && $link['href'] !== '')
        ->values();

    $eyebrow = $section['eyebrow'] ?? $defaultEyebrow;
    $title = $section['title'] ?? $defaultTitle;
@endphp

@if ($links->isNotEmpty())
    <div class="py-10 md:py-12" style="background-color: var(--color-surface);" {{ $stripAttribute }}>
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <p class="text-xs font-bold tracking-widest uppercase mb-2" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
                <h2 class="text-2xl md:text-3xl font-extrabold" style="color: var(--color-text);" {!! $editor('title', 'plain') !!}>
                    {{ $title }}
                </h2>
            </div>
            <div class="flex flex-wrap gap-2 md:gap-3">
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}"
                       class="inline-flex items-center px-3 py-1.5 text-sm font-medium"
                       style="background-color: var(--color-surface-alt); color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-card);">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif
