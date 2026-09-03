@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Order'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-4">Order confirmed</h1>

        @if ($order)
            <p class="mb-3">Order {{ $order->number }}</p>
        @endif

        <p class="mb-6" style="color: var(--color-text-muted);">A receipt has been emailed to you. We'll pack the order next and send tracking when it ships.</p>

        <a
            href="/shop"
            class="inline-flex items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            style="min-height: 44px; min-width: 44px; padding: 0.75rem 1.25rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
        >Continue shopping</a>
    </div>
</x-shop.layout>
