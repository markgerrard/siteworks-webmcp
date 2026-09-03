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
        : ($section['title'] ?? 'Case Studies');
@endphp

{{-- Alternating 7/5 image+text blocks. Each row's image is on the
     opposite side from the previous. Metric chips render via
     Lucide icons via the site's icon helper. Stacks on mobile. --}}
<section class="py-16 lg:py-20" style="background-color: var(--color-surface-alt);">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if ($heading !== '')
            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-12 md:mb-16"
                style="color: var(--color-text-on-alt);
                       font-family: var(--font-display);
                       letter-spacing: var(--heading-letter-spacing);"
                {!! $editor('title', 'plain') !!}>
                {{ $heading }}
            </h2>
        @endif

        @if ($items->isEmpty())
            <p class="text-sm" style="color: var(--color-text-muted-on-alt);">
                No case studies yet.
            </p>
        @else
            <div class="space-y-16 md:space-y-28">
                @foreach ($items as $index => $item)
                    @php $imageFirst = $index % 2 === 0; @endphp

                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 md:gap-10 items-center">
                        <div class="md:col-span-7 overflow-hidden
                                    {{ $imageFirst ? 'md:order-1' : 'md:order-2' }}"
                             style="background-color: var(--color-surface);
                                    border-radius: var(--radius-card);">
                            @if ($item->image?->url)
                                {{-- ?v=<media_id> busts the browser cache when
                                     a regen swaps the file at the deterministic
                                     sites/{site}/project_items/{id}.jpg path. --}}
                                <img class="w-full aspect-[16/9] object-cover"
                                     src="{{ $item->image->url }}?v={{ $item->image->id }}"
                                     alt="{{ $item->image->alt_text ?? $item->title }}"
                                     loading="lazy">
                            @else
                                <div class="w-full aspect-[16/9] flex items-center justify-center">
                                    <span class="text-xs font-bold uppercase tracking-widest"
                                          style="color: var(--color-text-muted);">
                                        {{ $item->category }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <div class="md:col-span-5 {{ $imageFirst ? 'md:order-2' : 'md:order-1' }}">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="inline-block w-8 h-[2px]"
                                      style="background-color: var(--color-accent);"></span>
                                <span class="text-xs font-bold uppercase tracking-widest"
                                      style="color: var(--color-accent-text-on-alt);">
                                    {{ $item->category }}
                                    @if ($vocab?->shouldShowExampleBadge($item))
                                        <span class="ml-1 opacity-70">· Example</span>
                                    @endif
                                </span>
                            </div>

                            <h3 class="text-2xl md:text-3xl lg:text-4xl font-black tracking-tight mb-4 leading-tight"
                                style="color: var(--color-text-on-alt);
                                       font-family: var(--font-display);">
                                {{ $item->title }}
                            </h3>

                            <p class="text-base md:text-lg leading-relaxed mb-6"
                               style="color: var(--color-text-muted-on-alt);">
                                {{ $item->description }}
                            </p>

                            @if (! empty($item->metrics))
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                                    @foreach ($item->metrics as $metric)
                                        <span class="inline-flex items-center gap-2 text-sm font-bold uppercase tracking-widest"
                                              style="color: var(--color-text-muted-on-alt);">
                                            <i data-lucide="{{ $metric['icon'] ?? 'check-circle' }}"
                                               class="w-5 h-5"
                                               style="color: var(--color-accent-text-on-alt);"></i>
                                            {{ $metric['label'] ?? '' }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
