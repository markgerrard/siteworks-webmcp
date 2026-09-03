@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account', 'href' => route('shop.account')],
            ['label' => 'Settings'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-6">Account settings</h1>

        <livewire:shop.account-settings />
    </div>
</x-shop.layout>
