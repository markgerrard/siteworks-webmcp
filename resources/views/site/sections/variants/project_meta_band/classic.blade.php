@php
    $rows = [
        ['key' => 'project_type', 'label' => 'Project type', 'value' => trim((string) ($section['project_type'] ?? ''))],
        ['key' => 'areas_covered', 'label' => 'Areas covered', 'value' => trim((string) ($section['areas_covered'] ?? ''))],
        ['key' => 'location', 'label' => 'Location', 'value' => trim((string) ($section['location'] ?? ''))],
    ];
    // Public render suppresses empty columns (bare labels over nothing);
    // admin-edit keeps them so the slots stay editable.
    if (! $emitMarkers) {
        $rows = array_values(array_filter($rows, fn ($row) => $row['value'] !== ''));
    }
    $colClass = ['', 'md:grid-cols-1', 'md:grid-cols-2', 'md:grid-cols-3'][count($rows)] ?? 'md:grid-cols-3';
    $rowCount = count($rows);
@endphp
@if ($rowCount > 0)
<section class="py-10 md:py-12" style="background-color: var(--color-surface);" data-project-meta-band data-svc-variant="{{ $svcVariant }}">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 {{ $colClass }}">
            @foreach ($rows as $index => $row)
                <div class="py-6 md:py-2 md:px-14 {{ $index === 0 ? 'md:pl-0' : '' }} {{ $index === $rowCount - 1 ? 'md:pr-0' : '' }} {{ $index < $rowCount - 1 ? 'md:border-r' : '' }}"
                     @if ($index < $rowCount - 1) style="border-right: 1px solid var(--color-border);" @endif>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] mb-2" style="color: var(--color-text-muted);">{{ $row['label'] }}</p>
                    @if ($row['value'] !== '')
                        <p class="text-base font-semibold" style="color: var(--color-text);" {!! $editor($row['key'], 'plain') !!}>{{ $row['value'] }}</p>
                    @elseif ($emitMarkers)
                        <span class="hidden"{!! $editor($row['key'], 'plain') !!}></span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif
