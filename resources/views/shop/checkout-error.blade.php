<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-10 max-w-full">
        <div
            class="w-full max-w-xl rounded p-6"
            style="color: var(--color-text); border: 1px solid var(--color-border); background-color: var(--color-surface-alt); border-radius: var(--radius-card);"
        >
            <h1 class="text-2xl font-bold">We couldn’t start checkout</h1>
            <p class="mt-3" style="color: var(--color-text-muted)">
                One or more items are no longer available in the quantity you requested.
            </p>
            <p class="mt-2">Return to your cart to review the items and try again.</p>
            <a
                href="/shop/cart"
                class="mt-5 inline-flex items-center justify-center px-4 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="min-height: 44px; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
            >Return to your cart</a>
        </div>
    </div>
</x-shop.layout>
