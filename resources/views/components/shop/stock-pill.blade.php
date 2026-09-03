@props([
    'state' => 'in',
    'remaining' => null,
])

@php
    $text = match ($state) {
        'low' => 'Only '.(int) $remaining.' left',
        'out' => 'Out of stock',
        default => 'In stock',
    };
    $color = $state === 'in' ? 'var(--color-text-muted)' : 'var(--color-accent-text)';
@endphp
<span {{ $attributes->merge(['class' => 'text-sm']) }} style="color: {{ $color }}">{{ $text }}</span>
