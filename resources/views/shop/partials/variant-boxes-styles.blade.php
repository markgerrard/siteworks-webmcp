{{-- PDP variant picker: price-labelled radio boxes. @once so a page that
     re-includes this partial (or a future listing of several pickers) emits
     the rules a single time. --}}
@once
<style>
        .shop-variant-boxes__grid {
            display: grid;
            gap: .5rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        @media (min-width: 640px) {
            .shop-variant-boxes__grid[data-count="3"],
            .shop-variant-boxes__grid[data-count="6"] {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        .shop-variant-box {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .125rem;
            padding: .75rem .5rem;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            background: var(--color-surface);
            cursor: pointer;
            text-align: center;
        }
        .shop-variant-box__input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            margin: -1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
        }
        .shop-variant-box:has(.shop-variant-box__input:checked) {
            border-color: var(--brand-accent);
            box-shadow: 0 0 0 1px var(--brand-accent) inset;
            background: color-mix(in oklab, var(--brand-accent) 12%, var(--color-surface));
        }
        .shop-variant-box:has(.shop-variant-box__input:focus-visible) {
            outline: 2px solid var(--color-accent);
            outline-offset: 2px;
        }
        .shop-variant-box:has(.shop-variant-box__input:disabled) {
            opacity: .55;
            cursor: not-allowed;
        }
        .shop-variant-box:has(.shop-variant-box__input:disabled) .shop-variant-box__price {
            text-decoration: line-through;
        }
        .shop-variant-box__label {
            font-weight: 600;
            font-size: .875rem;
            color: var(--color-text);
        }
        .shop-variant-box__price {
            font-size: .875rem;
            color: var(--color-text);
        }
        .shop-variant-box__note {
            font-size: .75rem;
            color: var(--color-text-muted);
        }
</style>
@endonce
