@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account', 'href' => route('shop.account')],
            ['label' => 'Orders'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-6">My orders</h1>

        @if ($orders->isEmpty())
            <x-shop.empty-state message="You have no orders yet." action="Browse the shop" href="/shop" />
        @else
            <ul class="divide-y max-w-full" style="border-color: var(--color-border);">
                @foreach ($orders as $o)
                    <li class="py-4 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a
                                href="{{ route('shop.account.order', ['orderId' => $o->id]) }}"
                                class="font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="color: var(--color-text); outline-color: var(--color-accent);"
                            >{{ $o->number }}</a>
                            <div class="mt-1 text-sm" style="color: var(--color-text-muted)">
                                {{ $o->placed_at->format('d M Y') }}
                                · <x-shop.price :amount="\App\Support\ShopMoney::format($o->total_cents, $site->shop_currency ?? 'GBP')" />
                            </div>
                        </div>
                        <x-shop.status-pill :status="$o->status" />
                    </li>
                @endforeach
            </ul>
            {{ $orders->links() }}
        @endif
    </div>
</x-shop.layout>
