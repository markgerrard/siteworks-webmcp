@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };
    $eyebrow = $section['eyebrow'] ?? 'Why Choose Us';
@endphp

<div class="py-16 lg:py-20 bg-white">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="text-center mb-16">
                <span class="text-sm font-bold tracking-widest uppercase mb-4 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                <h2 class="text-4xl md:text-5xl font-extrabold text-gray-900 leading-tight text-balance"
                    {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif
        @endif

        @if (!empty($section['items']))
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($section['items'] as $i => $item)
                    <div class="bg-gray-50 p-9 rounded-lg text-center" style="flex: 0 1 calc(33.333% - 1.34rem); min-width: 280px;">
                        <div class="w-16 h-16 rounded-full mx-auto mb-6 flex items-center justify-center shadow-sm"
                             style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3 leading-snug"
                            {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                        <p class="text-gray-600 text-base leading-relaxed"
                           {!! $editor("items.{$i}.body", 'plain') !!}>{{ $item['body'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
