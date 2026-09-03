@php
    $images = collect();
    $imageIds = $section['image_ids'] ?? [];
    if (is_array($imageIds) && $imageIds !== []) {
        $images = collect($imageIds)
            ->map(fn ($id) => ($mediaById ?? collect())->get((int) $id))
            ->filter()
            ->values();
    } else {
        $itemIds = $section['item_ids'] ?? [];
        if (is_array($itemIds)) {
            foreach ($itemIds as $id) {
                $item = ($itemsById ?? collect())->get((int) $id);
                if (! $item) {
                    continue;
                }
                if ($item->image) {
                    $images->push($item->image);
                }
                foreach ($item->galleryImages ?? [] as $galleryImage) {
                    $images->push($galleryImage);
                }
            }
        }
    }

    $essayMedia = $images->values()->all();
@endphp
<section class="py-16 lg:py-20" style="background-color: var(--color-surface);" data-project-photo-essay data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 space-y-8 md:space-y-12">
        @if (! empty($section['title']))@if (($section['__options']['detail_heading'] ?? null) === 'ruled')
            <div class="pt-4 mb-2" style="border-top: 2px solid var(--brand-accent);">
                <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $section['eyebrow'] ?? 'The work' }}</span>
            </div>
@else
            <div class="mb-2">
                <span class="text-xs font-bold tracking-[0.18em] uppercase" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $section['eyebrow'] ?? 'The work' }}</span>
            </div>
@endif
            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight"
                style="color: var(--color-text); font-family: var(--font-display);"
                {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            @if (! empty($section['intro']))
                <p class="mt-3 text-base md:text-lg max-w-3xl" style="color: var(--color-text-muted);" {!! $editor('intro', 'plain') !!}>{{ $section['intro'] }}</p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('intro', 'plain') !!}></span>
            @endif
        @elseif ($emitMarkers)
            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
        @endif

        {{-- Image-led 2-up: uniform 3:2 crops, caption always visible on a
             bottom scrim (no hover-gated content), index in the same row. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6">
            @foreach ($essayMedia as $i => $media)
                @php
                    $essayCaption = is_array($media->metadata ?? null) ? ($media->metadata['caption'] ?? null) : null;
                    $essayTitle = is_array($media->metadata ?? null) ? ($media->metadata['title'] ?? null) : null;
                @endphp
                <figure class="relative overflow-hidden" style="background-color: var(--color-surface-alt);">
                    <img class="w-full aspect-[3/2] object-cover" src="{{ $media->url }}?v={{ $media->id }}" alt="{{ $media->alt_text ?? '' }}" loading="lazy">
                    <figcaption class="absolute inset-x-0 bottom-0 px-4 pb-3 pt-10 flex items-baseline justify-between gap-4" style="background: linear-gradient(to top, rgba(0, 0, 0, 0.65), transparent);">
                        @if ($essayTitle)
                            <div class="text-xl font-extrabold text-white">{{ $essayTitle }}</div>
                        @endif
                        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-white">{{ $essayCaption ?? '' }}</span>
                        <span class="text-[10px] font-bold tracking-[0.18em] text-white/80">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
