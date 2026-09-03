@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-4">Check your inbox</h1>

        <p style="color: var(--color-text-muted);">We sent a sign-in link to {{ $email }}. Click it to continue.</p>
    </div>
</x-shop.layout>
