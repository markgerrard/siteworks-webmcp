{{-- Product-card hover/pill/zoom styles. Shared by the shop layout and any site-layout
     section that renders shop.partials.product-card (e.g. featured_products), so the card
     looks and behaves the same wherever it appears. --}}
@once
<style>
        .shop-product-card__img {
            transform: scale(1);
            transition: transform 400ms ease-out;
        }
        .shop-product-card:hover .shop-product-card__img,
        .shop-product-card:focus-within .shop-product-card__img {
            transform: scale(1.04);
        }
        .shop-product-card__pill-slot {
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            aspect-ratio: 1 / 1;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 7.5%;
            pointer-events: none;
            opacity: 0;
        }
        .shop-product-card__pill-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            padding: 0 1.25rem;
            background-color: var(--brand-accent);
            color: var(--color-text-on-accent, #ffffff);
            border: 1px solid var(--brand-accent);
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            outline-color: var(--color-accent);
        }
        /* The slot is a transparent overlay covering the whole image: it must never take
           pointer events itself (clicks on the image keep navigating via the card anchor);
           only the pill button becomes clickable when revealed. */
        @media (hover: hover) {
            .shop-product-card:hover .shop-product-card__pill-slot {
                opacity: 1;
            }
            .shop-product-card:hover .shop-product-card__pill-btn {
                pointer-events: auto;
            }
        }
        .shop-product-card:focus-within .shop-product-card__pill-slot {
            opacity: 1;
        }
        .shop-product-card:focus-within .shop-product-card__pill-btn {
            pointer-events: auto;
        }
        @media (prefers-reduced-motion: reduce) {
            .shop-product-card__img { transition: none; }
        }
</style>
@endonce
