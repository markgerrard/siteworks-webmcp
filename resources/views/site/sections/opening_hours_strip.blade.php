@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $rows = \App\Support\OpeningHours::rows($profile['opening_hours'] ?? null);
    $eyebrow = $section['eyebrow'] ?? 'Opening hours';
@endphp

@if ($rows !== [])
    <div class="py-8 md:py-10{{ \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !== '' ? ' relative overflow-hidden' : '' }}" style="background-color: var(--color-surface-alt); border-top: 1px solid var(--color-border); border-bottom: 1px solid var(--color-border);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-5">
                <p class="text-xs font-bold tracking-widest uppercase mb-1" style="color: var(--brand-accent-text-on-alt);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
                @if (!empty($section['title']))
                    <h3 class="text-xl md:text-2xl font-extrabold" style="color: var(--color-text-on-alt);">{{ $section['title'] }}</h3>
                @endif
            </div>
            @php
                // Full class literals (not interpolated) so Tailwind's
                // scanner and the Play CDN at-parser both pick them up.
                $lgCols = match (min(count($rows), 7)) {
                    1 => 'lg:grid-cols-1',
                    2 => 'lg:grid-cols-2',
                    3 => 'lg:grid-cols-3',
                    4 => 'lg:grid-cols-4',
                    5 => 'lg:grid-cols-5',
                    6 => 'lg:grid-cols-6',
                    default => 'lg:grid-cols-7',
                };
            @endphp
            <div class="grid gap-x-8 gap-y-2 sm:grid-cols-2 {{ $lgCols }} max-w-4xl mx-auto">
                @foreach ($rows as $row)
                    <div class="flex items-baseline justify-between gap-3 py-1">
                        <span class="text-sm font-semibold" style="color: var(--color-text-on-alt);">{{ $row['day'] }}</span>
                        <span class="text-sm font-mono" style="color: var(--color-text-muted-on-alt);">{{ $row['hours'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
