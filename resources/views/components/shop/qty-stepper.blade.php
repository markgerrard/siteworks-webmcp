@props([
    'name' => 'qty',
    'value' => 1,
    'min' => 1,
    'max' => 99,
])

@php
    $minQty = (int) $min;
    $maxQty = (int) $max;
    $qty = (int) $value;
@endphp
{{-- x-data MUST precede the attribute-bag expression. AlpineHandlerScopeTest parses
     Blade source and strips quoted attribute values; the quotes inside
     {{ $attributes->merge(['class' => '...']) }} swallow anything after it, so an
     x-data written last is invisible to the guard and the component reads as inert. --}}
<div x-data="{ qty: {{ $qty }} }" {{ $attributes->merge(['class' => 'inline-flex items-center']) }}>
    <button
        type="button"
        aria-label="Decrease quantity"
        @click="qty = Math.max({{ $minQty }}, Number(qty) - 1)"
        class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
        style="min-width: 44px; min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: transparent; outline-color: var(--color-accent);"
    >−</button>
    <input
        type="number"
        name="{{ $name }}"
        inputmode="numeric"
        min="{{ $minQty }}"
        max="{{ $maxQty }}"
        value="{{ $qty }}"
        x-model.number="qty"
        class="tabular-nums text-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
        style="min-width: 44px; min-height: 44px; width: 4rem; color: var(--color-text); border: 1px solid var(--color-border); background-color: transparent; outline-color: var(--color-accent);"
    >
    <button
        type="button"
        aria-label="Increase quantity"
        @click="qty = Math.min({{ $maxQty }}, Number(qty) + 1)"
        class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
        style="min-width: 44px; min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: transparent; outline-color: var(--color-accent);"
    >+</button>
</div>
