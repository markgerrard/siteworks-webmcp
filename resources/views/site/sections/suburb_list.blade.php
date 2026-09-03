@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Area source priority:
    //   1. $section['areas']  — explicit content (the content writer writes
    //      meta.geo.nearby_areas into this slot during composition)
    //   2. $section['items']  — alt content shape
    //   3. $profile['geo']['suburbs']  — legacy profile-derived source
    //      (unused in current pipelines but preserved for backward compat)
    // The section is deliberately "Areas Covered"-neutral — the module
    // type is still `suburb_list` internally (pre-UK rename) but every
    // user-facing string renders as "Areas" / "Where we work" which is
    // neutral enough for UK, IE, AU, NZ, and US markets.
    $source = null;
    if (! empty($section['areas']) && is_array($section['areas'])) {
        $source = $section['areas'];
    } elseif (! empty($section['items']) && is_array($section['items'])) {
        $source = $section['items'];
    } elseif (! empty($profile['geo']['suburbs']) && is_array($profile['geo']['suburbs'])) {
        $source = $profile['geo']['suburbs'];
    }

    $areas = is_array($source)
        ? array_values(array_unique(array_filter(
            array_map(fn ($s) => is_string($s) ? trim($s) : null, $source),
            fn ($s) => $s !== null && $s !== '',
        )))
        : [];

    // Threshold of 5: fewer than 5 areas reads as sparse and hurts
    // credibility more than it helps. If a prospect only covers 3–4
    // towns, list them in copy instead of as a "coverage grid".
    $shouldRender = count($areas) >= 5;
    $titleEyebrow = $section['eyebrow'] ?? 'Service area';
    $fallbackEyebrow = $section['eyebrow'] ?? 'Where we work';
@endphp

@if ($shouldRender)
    <div class="site-section-spacing" style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            @if (!empty($section['title']))
                <div class="text-center mb-8">
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $titleEyebrow }}</span>
                    <h2 class="text-3xl md:text-4xl font-extrabold" style="color: var(--color-text);">
                        {{ $section['title'] }}
                    </h2>
                    @if (!empty($section['intro']))
                        <p class="mt-3 text-base max-w-2xl mx-auto" style="color: var(--color-text-muted);">
                            {{ $section['intro'] }}
                        </p>
                    @endif
                </div>
            @else
                <div class="text-center mb-6">
                    <span class="text-sm font-bold tracking-widest uppercase mb-2 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $fallbackEyebrow }}</span>
                    <h2 class="text-2xl md:text-3xl font-extrabold" style="color: var(--color-text);">
                        Areas we cover
                    </h2>
                </div>
            @endif

            {{-- Real <ul>/<li> so assistive tech announces "list of N
                 items, item 1, …" instead of running every area into
                 one prose blob. list-none + p-0 strip the browser
                 default bullet/padding so the chip styling stays
                 unchanged. The chips aren't linked anywhere (no
                 per-area landing pages exist yet) — a non-navigating
                 link harms a11y, so plain list items it is. --}}
            <ul role="list"
                class="list-none p-0 m-0 flex flex-wrap justify-center gap-2 sm:gap-3 max-w-4xl mx-auto">
                @foreach ($areas as $area)
                    <li class="inline-block text-center text-sm md:text-[0.95rem] font-medium px-4 py-2"
                        style="background-color: var(--color-surface-alt); color: var(--color-text); border-radius: var(--radius-button);">
                        {{ $area }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
