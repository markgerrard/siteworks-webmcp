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

        {{-- Editorial tile grid (House Plus gallery grammar): 12-col rows
             alternating 7/5 and 5/7 spans, uniform 4:3 crops, and a caption
             row under every image — hairline, small-caps caption left,
             accent index numeral right. No full-width monsters. --}}
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 md:gap-8">
            @foreach ($essayMedia as $i => $media)
                @php
                    $span = ($i % 4 === 0 || $i % 4 === 3) ? 'md:col-span-7' : 'md:col-span-5';
                    $essayCaption = is_array($media->metadata ?? null) ? ($media->metadata['caption'] ?? null) : null;
                    $essayTitle = is_array($media->metadata ?? null) ? ($media->metadata['title'] ?? null) : null;
                @endphp
                <figure class="{{ $span }}">
                    <div class="overflow-hidden" style="background-color: var(--color-surface-alt);">
                        <img class="w-full aspect-[4/3] object-cover" src="{{ $media->url }}?v={{ $media->id }}" alt="{{ $media->alt_text ?? '' }}" loading="lazy">
                    </div>
                    <figcaption class="mt-3 pt-3 flex items-baseline justify-between gap-4" style="border-top: 1px solid var(--color-border);">
                        @if ($essayTitle)
                            <div class="text-xl font-extrabold " style="color: var(--color-text); font-family: var(--font-display);">{{ $essayTitle }}</div>
                        @endif
                        <span class="text-[10px] font-bold uppercase tracking-[0.18em]" style="color: var(--color-text-muted);">{{ $essayCaption ?? '' }}</span>
                        <span class="text-[10px] font-bold tracking-[0.18em]" style="color: var(--brand-accent-text);">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
