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
    $richHtml = fn ($val) => app(\App\Services\Site\RichTextRenderer::class)->renderValue($val);

    // Supporting detail image — mirrors the service-page intro section
    // pattern. When the about page has an active slot='intro' row in
    // hero_versions (populated by BuildPreviewJob for about-page's
    // generate_hero_image call), it's passed here via $introImageUrl.
    // Falls back gracefully to text-only if no intro was generated.
    $introImg = $introImageUrl ?? null;
    if (is_array($introImg)) {
        $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);
        $introImg = ($watermarkOn && ! empty($introImg['watermark_url']))
            ? $introImg['watermark_url']
            : ($introImg['url'] ?? null);
    }
    $eyebrow = $section['eyebrow'] ?? 'About Us';
@endphp

<div class="py-16 bg-white">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if ($introImg)
            {{-- Two-column layout when an image is available — mirrors
                 the service-page intro.blade.php pattern (2/5 image, 3/5
                 text). Stacks on mobile. --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-12 items-start">
                <div class="lg:col-span-2">
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @if (!empty($section['title']))
                        <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 leading-tight text-pretty"
                            {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                    @else
                        @if ($emitMarkers)
                            <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                        @endif
                    @endif
                    <div class="w-16 h-1 rounded-full" style="background-color: var(--brand-accent);"></div>
                    <div class="mt-8 overflow-hidden"
                         style="aspect-ratio: 4 / 3; border-radius: var(--radius-card); box-shadow: 0 10px 30px -12px rgba(0,0,0,0.25);">
                        <img src="{{ $introImg }}"
                             alt="{{ $section['title'] ?? 'About us' }}"
                             class="w-full h-full object-cover"
                             loading="lazy">
                    </div>
                </div>
                <div class="lg:col-span-3">
                    @if (!empty($section['body']))
                        <div class="space-y-5 text-gray-600 text-lg leading-relaxed prose prose-lg max-w-none"
                             {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
                    @else
                        @if ($emitMarkers)
                            <span class="hidden"{!! $editor('body', 'rich') !!}></span>
                        @endif
                    @endif
                </div>
            </div>
        @else
            {{-- Text-only fallback — preserves legacy rendering for
                 about pages that don't yet have an intro image. --}}
            <div class="max-w-4xl mx-auto">
                @if (!empty($section['title']))
                    <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 mb-6 leading-tight"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                    <div class="w-16 h-1 rounded-full mb-8" style="background-color: var(--brand-accent);"></div>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                @endif

                @if (!empty($section['body']))
                    <div class="prose prose-lg max-w-none text-gray-600 leading-relaxed"
                         {!! $editor('body', 'rich', is_array($section['body']) ? $section['body'] : null) !!}>{!! $richHtml($section['body']) !!}</div>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('body', 'rich') !!}></span>
                    @endif
                @endif
            </div>
        @endif
    </div>
</div>
