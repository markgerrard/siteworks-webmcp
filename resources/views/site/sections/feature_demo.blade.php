@php
    $editor = function ($field, $type, $valueDoc = null) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        $attrs = ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        if ($type === 'rich' && $valueDoc !== null) {
            $attrs .= ' data-editable-doc="'.e(json_encode($valueDoc)).'"';
        }

        return $attrs;
    };

    $eyebrow       = $section['eyebrow'] ?? null;
    $title         = $section['title'] ?? null;
    $caption       = $section['caption'] ?? null;
    $videoUrl      = $section['video_url'] ?? null;
    $imageUrl      = $section['image_url'] ?? null;
    $bottomCaption = $section['bottom_caption'] ?? null;

    // Video takes precedence over image when both are present.
    $hasVideo = !empty($videoUrl);
    $hasImage = !empty($imageUrl);
    $hasMedia = $hasVideo || $hasImage;
@endphp

@if ($hasMedia || !empty($title))
    <div class="site-section-spacing" style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">

            {{-- Optional centred header --}}
            @if (!empty($eyebrow) || !empty($title) || !empty($caption))
                <div class="text-center mb-10 max-w-3xl mx-auto">
                    @if (!empty($eyebrow))
                        <span class="text-sm font-semibold uppercase tracking-wider mb-3 block"
                              style="color: var(--brand-accent);"
                              {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                    @endif

                    @if (!empty($title))
                        <h2 class="text-2xl md:text-3xl font-bold leading-tight text-balance"
                            style="color: var(--color-primary);"
                            {!! $editor('title', 'plain') !!}>{{ $title }}</h2>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif

                    @if (!empty($caption))
                        <p class="mt-3 text-lg leading-relaxed"
                           style="color: var(--color-text-muted);"
                           {!! $editor('caption', 'plain') !!}>{{ $caption }}</p>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor('caption', 'plain') !!}></span>
                    @endif
                </div>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                <span class="hidden"{!! $editor('caption', 'plain') !!}></span>
            @endif

            {{-- Main media block --}}
            @if ($hasMedia)
                <div class="overflow-hidden rounded-2xl shadow-2xl max-w-5xl mx-auto"
                     style="border: 1px solid var(--color-border);">
                    @if ($hasVideo)
                        <video autoplay muted loop playsinline
                               class="w-full h-auto block"
                               src="{{ $videoUrl }}">
                            @if ($hasImage)
                                {{-- Fallback poster for browsers that can't autoplay --}}
                                Your browser does not support the video tag.
                            @endif
                        </video>
                    @elseif ($hasImage)
                        <img src="{{ $imageUrl }}"
                             alt="{{ $title ?? 'Product demo' }}"
                             loading="lazy"
                             class="w-full h-auto block">
                    @endif
                </div>
            @endif

            {{-- Optional bottom caption --}}
            @if (!empty($bottomCaption))
                <p class="mt-6 text-center text-sm italic max-w-2xl mx-auto"
                   style="color: var(--color-text-muted);"
                   {!! $editor('bottom_caption', 'plain') !!}>{{ $bottomCaption }}</p>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('bottom_caption', 'plain') !!}></span>
            @endif

        </div>
    </div>
@endif
