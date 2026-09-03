@php
    /**
     * A product with no photo still gets a deliberate tile: the brand band colour,
     * the product name in the display face, and a caption saying the photo is on
     * its way. Rendered at request time from the product name alone, so a product
     * added without imagery looks intended the moment it is published, with no
     * snapshot rebuild and no <img> pointing at nothing.
     *
     * @var string $name
     * @var string $size  'card' or 'full' — sets the type scale only.
     */
    $size = $size ?? 'card';
    $nameSize = $size === 'full' ? 'clamp(1.5rem, 4vw, 2.5rem)' : 'clamp(1rem, 2.2vw, 1.35rem)';
    $captionSize = $size === 'full' ? '0.875rem' : '0.75rem';
@endphp
<div
    class="shop-product-placeholder w-full h-full flex flex-col items-center justify-center text-center"
    style="aspect-ratio: 1 / 1; padding: 12%; background-color: var(--color-band); color: var(--color-text-on-band);"
    data-shop-image-placeholder
    aria-label="{{ $name }} — photo coming soon"
>
    <span class="shop-product-placeholder__name break-words" style="font-family: var(--font-display); font-size: {{ $nameSize }}; line-height: 1.15; letter-spacing: var(--heading-letter-spacing);">{{ $name }}</span>
    <span class="shop-product-placeholder__caption mt-2 uppercase" style="font-size: {{ $captionSize }}; letter-spacing: 0.08em; opacity: 0.72;">Photo coming soon</span>
</div>
