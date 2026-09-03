@php
    $eyebrow = $section['eyebrow'] ?? 'More of our work';
    $heading = $section['title'] ?? 'Similar projects';
@endphp
<section class="py-16 lg:py-20" style="background-color: var(--color-surface);" data-similar-projects data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="mb-8">
            @if (empty($section['__suppress_eyebrow']))
                <p class="text-xs font-bold tracking-[0.18em] uppercase mb-2" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
            @endif
            <h2 class="text-2xl md:text-3xl font-extrabold" style="color: var(--color-text); font-family: var(--font-display);" {!! $editor('title', 'plain') !!}>{{ $heading }}</h2>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 md:gap-8">
            @foreach ($candidates as $item)
                @php
                    $detail = $item->detailPage;
                    $href = $detail
                        ? ($pagesBySlug[$detail->page_type] ?? '/'.$detail->page_type)
                        : null;
                    $tag = $href ? 'a' : 'div';
                @endphp
                <{{ $tag }} @if ($href) href="{{ $href }}" @endif class="group block overflow-hidden" style="background-color: var(--color-surface-alt);">
                    @if ($item->image?->url)
                        <img class="w-full aspect-[4/3] object-cover" src="{{ $item->image->url }}?v={{ $item->image->id }}" alt="{{ $item->image->alt_text ?? $item->title }}" loading="lazy">
                    @else
                        <div class="w-full aspect-[4/3]" style="background-color: var(--color-surface-alt);"></div>
                    @endif
                    <div class="pt-4">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] mb-1" style="color: var(--color-text-muted);">{{ $item->category }}</p>
                        <p class="text-lg font-semibold" style="color: var(--color-text);">{{ $item->title }}</p>
                    </div>
                </{{ $tag }}>
            @endforeach
        </div>
    </div>
</section>
