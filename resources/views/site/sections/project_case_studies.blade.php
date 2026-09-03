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
    $heading = $vocab
        ? $vocab->caseStudyHeading($items)
        : ($section['title'] ?? 'Featured Projects');
@endphp

{{-- Long-form per-project narrative blocks. Each project gets a hero image,
     descriptive copy, and tag chips drawn from the item's metrics array.
     Renders a single hero image; the SiteMedia.project_item_id FK
     is reserved for the multi-image grid below the narrative. --}}
<section class="py-16 lg:py-20" style="background-color: var(--color-surface);">
    <div class="site-shell-container mx-auto px-4 sm:px-6 lg:px-8" style="max-width: var(--container-width);">
        @if ($heading !== '')
            <div class="text-center mb-12 md:mb-16">
                <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight"
                    style="color: var(--color-text);
                           font-family: var(--font-display);
                           letter-spacing: var(--heading-letter-spacing);"
                    {!! $editor('title', 'plain') !!}>
                    {{ $heading }}
                </h2>
                <div class="w-16 h-1 rounded-full mx-auto mt-5"
                     style="background-color: var(--brand-accent);"></div>
            </div>
        @endif

        @if ($items->isEmpty())
            <p class="text-sm text-center" style="color: var(--color-text-muted);">
                No case studies yet.
            </p>
        @else
            <div class="space-y-20 md:space-y-28">
                @foreach ($items as $item)
                    <article class="max-w-4xl mx-auto">
                        {{-- Hero image — single shot. The
                             SiteMedia.project_item_id FK is the seam where
                             the multi-image grid drops in below. --}}
                        @if ($item->image?->url)
                            <div class="overflow-hidden mb-8 md:mb-10"
                                 style="border-radius: var(--radius-card);">
                                {{-- ?v=<media_id> busts the browser cache when a regen
                                     swaps the file at the deterministic project_items
                                     path. --}}
                                <img class="w-full aspect-[16/9] object-cover"
                                     src="{{ $item->image->url }}?v={{ $item->image->id }}"
                                     alt="{{ $item->image->alt_text ?? $item->title }}"
                                     loading="lazy">
                            </div>
                        @endif

                        <div class="text-center mb-6">
                            <span class="text-xs md:text-sm font-bold uppercase tracking-widest"
                                  style="color: var(--brand-accent-text);">
                                {{ $item->category }}
                                @if ($vocab?->shouldShowExampleBadge($item))
                                    <span class="ml-1 opacity-70">· Example</span>
                                @endif
                            </span>
                        </div>

                        <h3 class="text-2xl md:text-4xl font-black tracking-tight text-center mb-6 leading-tight text-balance"
                            style="color: var(--color-text);
                                   font-family: var(--font-display);">
                            {{ $item->title }}
                        </h3>

                        <p class="text-base md:text-lg leading-relaxed text-center max-w-2xl mx-auto"
                           style="color: var(--color-text-muted);">
                            {{ $item->description }}
                        </p>

                        {{-- Tag chips — drawn from the metrics array. The icon is
                             intentionally dropped here so the chips read as plain
                             pill tags (the metric icons live on case_study_highlights
                             where the alternating layout has more room to support them). --}}
                        @if (! empty($item->metrics))
                            <div class="flex flex-wrap items-center justify-center gap-2 mt-8">
                                @foreach ($item->metrics as $metric)
                                    @php $label = trim($metric['label'] ?? ''); @endphp
                                    @if ($label !== '')
                                        <span class="inline-block text-xs md:text-sm font-semibold px-3 py-1.5 rounded-full"
                                              style="background-color: var(--color-surface-alt);
                                                     color: var(--color-text-on-alt);
                                                     border: 1px solid var(--color-border);">
                                            {{ $label }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        {{-- Multi-image grid — populated when GenerateCaseStudyGalleryImageJob
                             has run for this item. Items with only the hero render the block
                             above and stop here. --}}
                        @php $extras = $item->galleryImages; @endphp
                        @if ($extras->isNotEmpty())
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-10">
                                @foreach ($extras as $extra)
                                    <div class="overflow-hidden"
                                         style="border-radius: var(--radius-card);">
                                        <img class="w-full aspect-[16/9] object-cover"
                                             src="{{ $extra->url }}?v={{ $extra->id }}"
                                             alt="{{ $extra->alt_text ?? $item->title }}"
                                             loading="lazy">
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
