@php
    $groups = is_array($groups ?? null) ? $groups : [];
    $state = is_array($state ?? null) ? $state : [];
    $sort = $state['sort'] ?? 'featured';
    $defaultSort = $state['defaultSort'] ?? 'featured';
@endphp
@if ($sort !== $defaultSort)
    <input type="hidden" name="sort" value="{{ $sort }}">
@endif
@foreach ($groups as $group)
    <details class="mt-2" style="border-bottom: 1px solid var(--color-border);" @if ($group['open']) open @endif>
        <summary class="flex items-center justify-between gap-2 py-3 text-sm font-semibold cursor-pointer" style="list-style: none; min-height: 44px;">
            <span>{{ $group['name'] !== '' ? $group['name'] : $group['values'][0]['label'] ?? '' }}</span>
            <span aria-hidden="true">▾</span>
        </summary>
        <div class="flex flex-col gap-2 pb-4">
            @foreach ($group['values'] as $value)
                <label class="inline-flex items-center gap-2 text-sm" style="min-height: 44px;">
                    <input
                        type="checkbox"
                        name="{{ $group['param'] }}[]"
                        value="{{ $value['id'] }}"
                        @checked($value['checked'])
                    >
                    <span>{{ $value['label'] }} ({{ $value['count'] }})</span>
                </label>
            @endforeach
        </div>
    </details>
@endforeach
