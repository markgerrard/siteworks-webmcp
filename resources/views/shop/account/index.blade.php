@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $linkStyle = 'color: var(--color-text); text-decoration: underline; text-underline-offset: 0.2em; outline-color: var(--color-accent);';
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-6">My account</h1>

        <ul class="space-y-3">
            @if ($site->shopShowsAccountOrders())
                <li>
                    <a href="{{ route('shop.account.orders') }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $linkStyle }}">My orders</a>
                </li>
            @endif
            @if (in_array($site->shop_mode ?? 'cart', ['enquire', 'quote'], true))
                <li>
                    <a href="{{ route('shop.account.enquiries') }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $linkStyle }}">Enquiries</a>
                </li>
            @endif
            <li>
                <a href="{{ route('shop.account.addresses') }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $linkStyle }}">Addresses</a>
            </li>
            <li>
                <a href="{{ route('shop.account.settings') }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $linkStyle }}">Settings</a>
            </li>
        </ul>

        @if (in_array($site->shop_mode ?? 'cart', ['enquire', 'quote'], true))
            <section class="mt-10">
                <h2 class="font-semibold mb-3">Your enquiries</h2>
                @if ($enquiries->isEmpty())
                    <p style="color: var(--color-text-muted)">You have no enquiries yet.</p>
                @else
                    <ul class="divide-y max-w-xl" style="border-color: var(--color-border);">
                        @foreach ($enquiries as $enquiry)
                            <li class="py-3">
                                <div class="font-semibold">{{ ($enquiry->payload['kind'] ?? null) === 'quote' ? 'Quote request' : ($enquiry->payload['product'] ?? 'Enquiry') }}</div>
                                <div class="text-sm mt-1" style="color: var(--color-text-muted)">
                                    {{ $enquiry->created_at->format('d M Y') }}
                                    · {{ $enquiry->status }}
                                </div>
                                @include('shop.partials.quote-enquiry-lines', ['enquiry' => $enquiry])
                                @if (! empty($enquiry->payload['message']))
                                    <p class="mt-1">{{ $enquiry->payload['message'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <form method="POST" action="/shop/account/logout" class="mt-8">
            @csrf
            <button
                type="submit"
                class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                style="min-height: 44px; min-width: 44px; padding: 0.5rem 0.75rem; color: var(--color-text-muted); background-color: transparent; border-radius: var(--radius-button); outline-color: var(--color-accent);"
            >Sign out</button>
        </form>
    </div>
</x-shop.layout>
