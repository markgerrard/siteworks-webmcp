@if (($group['kind'] ?? '') === 'text')
    <div>{!! nl2br(e($group['value']['text'] ?? '')) !!}</div>
@else
    <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
        @foreach ($group['value']['pairs'] ?? [] as $pair)
            @continue(trim((string) ($pair['label'] ?? '')) === '' && trim((string) ($pair['value'] ?? '')) === '')
            <dt class="font-medium">{{ $pair['label'] ?? '' }}</dt>
            <dd>{{ $pair['value'] ?? '' }}</dd>
        @endforeach
    </dl>
@endif
