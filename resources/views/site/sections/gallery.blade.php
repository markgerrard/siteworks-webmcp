@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $imageIds = $section['image_ids'] ?? [];
    $images = collect($imageIds)
        ->map(fn ($id) => ($mediaById ?? collect())->get((int) $id))
        ->filter()
        ->values();
    $heading = $section['title'] ?? 'Gallery';
@endphp

{{-- Simple responsive image grid for managed gallery sections (case
     studies). Conventions mirror project_gallery: lazy load, ?v= cache
     bust on media id, editor marker on the title. --}}
<section class="site-section-spacing" style="background-color: var(--color-surface);">
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

        @if ($images->isEmpty())
            <p class="text-sm" style="color: var(--color-text-muted);">
                No images to display yet.
            </p>
        @else
            @php
                $gridClasses = $images->count() >= 9
                    ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8'
                    : 'grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-10';
            @endphp
            <div class="{{ $gridClasses }}">
                @foreach ($images as $media)
                    <figure class="group relative overflow-hidden aspect-[4/5]"
                            style="background-color: var(--color-surface-alt);
                                   border-radius: var(--radius-card);">
                        @if ($media->url)
                            {{-- ?v=<media_id> busts the browser cache when a
                                 regen swaps the file at a deterministic path. --}}
                            <img class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                                 src="{{ $media->url }}?v={{ $media->id }}"
                                 alt="{{ $media->alt_text ?? '' }}"
                                 loading="lazy">
                        @endif
                    </figure>
                @endforeach
            </div>
        @endif
    </div>
</section>
