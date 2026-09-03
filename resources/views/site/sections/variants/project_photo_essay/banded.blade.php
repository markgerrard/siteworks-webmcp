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
<section data-project-photo-essay data-svc-variant="{{ $svcVariant }}">
    {{-- Heading gets top padding only; each band below carries its own
         vertical rhythm (avoids the py-16 + pb-0 contradiction). --}}
    <div class="site-shell-container px-4 sm:px-6 lg:px-8 pt-16 lg:pt-20 pb-8 lg:pb-10">
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
    </div>

    {{-- One full-width band per image, alternating backgrounds; image 7/12
         alternating sides, oversized accent index + caption facing it. --}}
    @foreach ($essayMedia as $i => $media)
        @php
            $essayCaption = is_array($media->metadata ?? null) ? ($media->metadata['caption'] ?? null) : null;
                    $essayTitle = is_array($media->metadata ?? null) ? ($media->metadata['title'] ?? null) : null;
            $flip = $i % 2 === 1;
        @endphp
        <div class="py-10 lg:py-14" style="background-color: {{ $flip ? 'var(--color-surface-alt)' : 'var(--color-surface)' }};">
            <div class="site-shell-container px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-8 items-center">
                    <figure class="md:col-span-7 {{ $flip ? 'md:order-2' : '' }} overflow-hidden" style="background-color: var(--color-surface-alt);">
                        <img class="w-full aspect-[3/2] object-cover" src="{{ $media->url }}?v={{ $media->id }}" alt="{{ $media->alt_text ?? '' }}" loading="lazy">
                    </figure>
                    <div class="md:col-span-5">
                        <span class="block text-4xl font-extrabold" style="color: var(--brand-accent-text); font-family: var(--font-display);">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <div class="mt-3 pt-3" style="border-top: 1px solid var(--color-border);">
                            @if ($essayTitle)
                                <div class="text-xl font-extrabold " style="color: var(--color-text); font-family: var(--font-display);">{{ $essayTitle }}</div>
                            @endif
                            <span class="text-[11px] font-bold uppercase tracking-[0.18em]" style="color: var(--color-text-muted);">{{ $essayCaption ?? '' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</section>
