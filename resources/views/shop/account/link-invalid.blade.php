@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account', 'href' => route('shop.account.login')],
            ['label' => 'Sign in'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-4">That sign-in link is no longer valid</h1>

        <p class="mb-3" style="color: var(--color-text-muted);">It has expired or has already been used. Request a new link to continue — we will only send one if the address can be used.</p>

        <a
            href="{{ route('shop.account.login') }}"
            class="inline-flex items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            style="min-height: 44px; min-width: 44px; padding: 0.75rem 1.25rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
        >Request a new sign-in link</a>
    </div>
</x-shop.layout>
