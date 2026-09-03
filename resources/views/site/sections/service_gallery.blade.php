@if (config('site.service_page_galleries_enabled'))
    @php
        $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
            if (! $emitMarkers) {
                return '';
            }
            $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
            $sectionType = $section['type'] ?? '';

            return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        };

        $category = is_string($section['category'] ?? null) ? $section['category'] : '';
        $items = ($serviceGalleryItems ?? collect())->get($category) ?? collect();
        $initialCount = 32;
        $heading = $section['title'] ?? 'Recent Work';
    @endphp

    @if ($items->isNotEmpty())
        {{-- Bottom padding is deliberately lighter than section spacing:
             the section that follows (FAQ) shares this background, so two
             full paddings stacked into a void — and the grid's short last
             row already leaves visual air. --}}
        <section class="pb-8 lg:pb-10" style="background-color: var(--color-surface); padding-top: var(--section-spacing);">
            <div class="site-shell-container px-4 sm:px-6 lg:px-8">
                @if ($heading !== '')
                    <h2 class="text-3xl md:text-5xl font-extrabold tracking-tight mb-12 md:mb-16"
                        style="color: var(--color-text);
                               font-family: var(--font-display);
                               letter-spacing: var(--heading-letter-spacing);"
                        {!! $editor('title', 'plain') !!}>
                        {{ $heading }}
                    </h2>
                @endif

                <div x-data="{ expanded: false }">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4">
                        @foreach ($items as $i => $item)
                            @continue(! $item->image?->url)
                            <figure class="group relative overflow-hidden aspect-square"
                                    style="background-color: var(--color-surface-alt); border-radius: var(--radius-card);"
                                    @if ($i >= $initialCount) x-show="expanded" x-cloak @endif>
                                <img class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
                                     src="{{ $item->image->url }}?v={{ $item->image->id }}"
                                     alt="{{ $item->title ?: $category }}"
                                     loading="lazy">
                            </figure>
                        @endforeach
                    </div>

                    @if ($items->count() > $initialCount)
                        <div class="mt-8 text-center" x-show="! expanded">
                            <button type="button" x-on:click="expanded = true"
                                    class="inline-flex items-center px-6 py-3 font-bold rounded-lg"
                                    style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                                View more ({{ $items->count() - $initialCount }})
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
@endif
