@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $formatAmount = function (mixed $value): string {
        if (! is_numeric($value)) {
            return is_string($value) ? $value : '';
        }

        $amount = (float) $value;
        $decimals = fmod($amount, 1.0) != 0.0 ? 2 : 0;

        return '£'.number_format($amount, $decimals);
    };

    $eyebrow = $section['eyebrow'] ?? null;
    $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
@endphp

@if (! empty($section['title']) || $rows !== [])
    <div class="site-section-spacing" style="background-color: var(--color-surface);">
        <div class="site-shell-container px-4 sm:px-6 lg:px-8">
            @if (! empty($section['title']))
                <div class="text-center mb-10">
                    @if (is_string($eyebrow) && $eyebrow !== '')
                        <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: var(--brand-accent-text);" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    @endif
                    <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-balance"
                        style="color: var(--color-text);"
                        {!! $editor('title', 'plain') !!}>{{ $section['title'] }}</h2>
                </div>
            @elseif ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
            @endif

            @if ($rows !== [])
                <div class="overflow-x-auto rounded-lg shadow-sm" style="border: 1px solid var(--color-border);">
                    <table class="min-w-full text-left text-sm">
                        <thead style="background-color: var(--color-surface-alt); color: var(--color-text-on-alt);">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">Job</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Low</th>
                                <th scope="col" class="px-4 py-3 font-semibold">High</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Basis</th>
                                <th scope="col" class="px-4 py-3 font-semibold">VAT</th>
                            </tr>
                        </thead>
                        <tbody style="background-color: var(--color-surface); color: var(--color-text);">
                            @foreach ($rows as $i => $row)
                                <tr style="border-top: 1px solid var(--color-border);">
                                    <td class="px-4 py-3 font-medium" {!! $editor("rows.{$i}.job", 'plain') !!}>{{ $row['job'] ?? '' }}</td>
                                    <td class="px-4 py-3 tabular-nums">{{ $formatAmount($row['low'] ?? null) }}</td>
                                    <td class="px-4 py-3 tabular-nums">{{ $formatAmount($row['high'] ?? null) }}</td>
                                    <td class="px-4 py-3">{{ $row['basis'] ?? '' }}</td>
                                    <td class="px-4 py-3">{{ $row['vat_note'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif
