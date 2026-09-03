@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $tiles = isset($site)
        ? app(\App\Services\Site\CategoryRailPicker::class)->tilesFor($site, $section, $mode ?? 'public')
        : [];
    $n = count($tiles);
    $colsClass = $n > 8 ? 'lg:grid-cols-6' : 'lg:grid-cols-4';
@endphp
@if ($tiles !== [])
    <div class="site-section-spacing relative overflow-hidden"
         data-category-rail
         style="background-color: var(--color-surface);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        <style>
            @@media (min-width: 1024px) {
                ul[data-category-rail] { display: grid; overflow: visible; }
                ul[data-category-rail] > li { width: auto !important; }
            }
        </style>
        <div class="site-shell-container px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center mb-12">
                @if (!empty($section['title']))
                    <h2 class="text-3xl md:text-4xl font-extrabold" style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                    @endif
                @endif
                @if (!empty($section['subtitle']))
                    <p class="mt-3 text-lg md:text-xl max-w-2xl mx-auto leading-relaxed" style="color: var(--color-text-muted);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $section['subtitle'] }}</p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                @endif
            </div>

            <ul class="list-none m-0 p-0 flex overflow-x-auto snap-x gap-4 {{ $colsClass }}" data-category-rail>
                @foreach ($tiles as $tile)
                    <li class="flex-shrink-0 snap-start" style="width: min(11rem, 70vw);">
                        <a href="{{ $tile['href'] }}" class="block">
                            <span class="block w-full overflow-hidden" style="aspect-ratio: 1 / 1; background-color: var(--color-surface-alt); border-radius: var(--radius-card);">
@if (! empty($tile['image_url']))
                                <img src="{{ $tile['image_url'] }}" alt="{{ $tile['alt'] }}" loading="lazy" decoding="async" width="400" height="400" class="w-full h-full object-cover">
@else
                                <span class="flex items-center justify-center w-full h-full text-3xl font-extrabold" style="font-family: var(--font-display); color: var(--color-text);">{{ mb_strtoupper(mb_substr($tile['name'], 0, 1)) }}</span>
@endif
                            </span>
                            <span class="block mt-2 text-sm font-semibold" style="color: var(--color-text);">{{ $tile['name'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
