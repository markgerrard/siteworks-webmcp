@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $body = $section['body'] ?? 'These are typical ranges, not a quote.';
    $validUntil = $section['valid_until'] ?? null;
    $generatedAt = $section['generated_at'] ?? null;
@endphp

<div class="site-section-spacing">
    <div class="site-shell-container mx-auto px-4 sm:px-6 lg:px-8" style="max-width: min(var(--container-width), 46rem);">
        <aside class="rounded-lg px-6 py-5 text-sm leading-relaxed"
               style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); color: var(--color-text-muted-on-alt);">
            <p {!! $editor('body', 'plain') !!}>{{ $body }}</p>
            @if (is_string($validUntil) && $validUntil !== '')
                <p class="mt-2">Figures valid until {{ $validUntil }}.</p>
            @endif
            @if (is_string($generatedAt) && $generatedAt !== '')
                <p class="mt-1" {!! $editor('generated_at', 'plain') !!}>Generated {{ $generatedAt }}.</p>
            @elseif ($emitMarkers)
                <p class="hidden"{!! $editor('generated_at', 'plain') !!}></p>
            @endif
        </aside>
    </div>
</div>
