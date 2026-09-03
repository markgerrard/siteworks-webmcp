@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Professional-service flavour: row of "we work with X / Y / Z" chips.
    // Source priority:
    //   1. $section['items'] (content_data — admin-editable list)
    //   2. $profile['audience_segments'] (profiler-emitted future field)
    //   3. fall back to splitting $profile['audience'] on commas
    $items = [];
    if (is_array($section['items'] ?? null)) {
        $items = array_values(array_filter(array_map(
            fn ($i) => is_array($i) ? ($i['title'] ?? $i['label'] ?? null) : (is_string($i) ? $i : null),
            $section['items'],
        ), fn ($s) => is_string($s) && trim($s) !== ''));
    } elseif (is_array($profile['audience_segments'] ?? null)) {
        $items = array_values(array_filter(
            array_map(fn ($s) => is_string($s) ? trim($s) : null, $profile['audience_segments']),
            fn ($s) => $s !== null && $s !== '',
        ));
    } elseif (is_string($profile['audience'] ?? null) && trim($profile['audience']) !== '') {
        $items = array_values(array_filter(array_map('trim', explode(',', $profile['audience']))));
    }
    $eyebrow = $section['eyebrow'] ?? 'Who we help';
@endphp

@if ($items !== [])
    <div class="py-10 md:py-12{{ \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !== '' ? ' relative overflow-hidden' : '' }}" style="background-color: var(--color-surface);">{!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, false, $site ?? null) !!}
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-6">
                <p class="text-xs font-bold tracking-widest uppercase mb-2" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</p>
                <h3 class="text-2xl md:text-3xl font-extrabold" style="color: var(--color-text);">
                    {{ $section['title'] ?? 'Trusted by' }}
                </h3>
            </div>
            <div class="flex flex-wrap justify-center gap-2 md:gap-3">
                @foreach (array_slice($items, 0, 12) as $label)
                    <span class="inline-flex items-center px-3 py-1.5 text-sm font-medium"
                          style="background-color: var(--color-surface-alt); color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button);">
                        {{ $label }}
                    </span>
                @endforeach
            </div>
        </div>
    </div>
@endif
