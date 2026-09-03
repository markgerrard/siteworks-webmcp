@props([
    'message' => 'Your cart is empty.',
    'action' => 'Browse the shop',
    'href' => '/shop',
])

<div {{ $attributes->merge(['style' => 'background-color: var(--color-surface-alt); border: 1px solid var(--color-border); border-radius: var(--radius-card); padding: 2.5rem 1.5rem; text-align: center; max-width: 36rem; margin: 0 auto;']) }}>
    <p style="color: var(--color-text-muted); margin: 0;">{{ $message }}</p>
    <a href="{{ $href }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="display: inline-block; min-height: 44px; min-width: 44px; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); padding: 0.75rem 1.25rem; outline-color: var(--color-accent); margin-top: 1.25rem;">{{ $action }}</a>
</div>
