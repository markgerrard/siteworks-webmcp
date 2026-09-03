@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    $label = ucfirst(str_replace('_', ' ', $value));
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center text-sm']) }} style="background-color: var(--color-surface-alt); color: var(--color-text-on-alt); border-radius: var(--radius-button); padding: 0.15em 0.65em;">{{ $label }}</span>
