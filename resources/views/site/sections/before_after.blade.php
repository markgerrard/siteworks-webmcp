@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $heading = $section['title'] ?? 'Transformation Stories';
    $pairIds = $section['pair_ids'] ?? [];
    // pairsById is preloaded by PageRenderer (mirrors the itemsById pattern).
    // Filter to pairs with BOTH images present — partial pairs (before-only)
    // hide rather than render with a broken column. The before/after framing
    // only tells a story when both halves exist.
    $pairs = collect($pairIds)
        ->map(fn ($id) => ($pairsById ?? collect())->get($id))
        ->filter(fn ($p) => $p && $p->before_image_id && $p->after_image_id)
        ->values();
@endphp

@if ($pairs->isNotEmpty())
<section class="py-16 lg:py-20" style="background-color: var(--color-surface-alt);">
    <div class="site-shell-container mx-auto px-4 sm:px-6 lg:px-8" style="max-width: var(--container-width);">
        <div class="text-center mb-12 md:mb-16">
            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight"
                style="color: var(--color-text-on-alt);
                       font-family: var(--font-display);
                       letter-spacing: var(--heading-letter-spacing);"
                {!! $editor('title', 'plain') !!}>
                {{ $heading }}
            </h2>
            <div class="w-16 h-1 rounded-full mx-auto mt-5"
                 style="background-color: var(--brand-accent);"></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 md:gap-12">
            @foreach ($pairs as $pair)
                <article class="flex flex-col">
                    {{-- Before / After image pair. Stacked on mobile so each
                         image gets full viewport width; side-by-side on sm+
                         so the contrast reads at a glance. --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-5">
                        <div class="relative overflow-hidden"
                             style="border-radius: var(--radius-card);">
                            <img class="w-full aspect-[4/3] object-cover"
                                 src="{{ $pair->beforeImage->url }}?v={{ $pair->beforeImage->id }}"
                                 alt="{{ $pair->beforeImage->alt_text ?? 'Before' }}"
                                 loading="lazy">
                            <span class="absolute top-2 left-2 text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded"
                                  style="background-color: rgba(0,0,0,0.65); color: white;">
                                Before
                            </span>
                        </div>
                        <div class="relative overflow-hidden"
                             style="border-radius: var(--radius-card);">
                            <img class="w-full aspect-[4/3] object-cover"
                                 src="{{ $pair->afterImage->url }}?v={{ $pair->afterImage->id }}"
                                 alt="{{ $pair->afterImage->alt_text ?? 'After' }}"
                                 loading="lazy">
                            <span class="absolute top-2 left-2 text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded"
                                  style="background-color: var(--brand-accent); color: var(--color-text-on-primary, #ffffff);">
                                After
                            </span>
                        </div>
                    </div>

                    <p class="text-base md:text-lg leading-relaxed"
                       style="color: var(--color-text-muted-on-alt);">
                        {{ $pair->narrative }}
                    </p>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif
