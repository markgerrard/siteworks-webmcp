@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $address = $order->shipping_address_json ?? [];
    $vatCents = $order->tax_cents + $order->shipping_tax_cents;
    $currency = $site->shop_currency ?? 'GBP';
    $taxCountry = $order->tax_country_code ?: $site->shopCountryCode();
    $showTaxLine = \App\Support\ShopMoney::includesVat($currency)
        || (int) $vatCents !== 0
        || app(\App\Services\Shop\TaxService::class)->hasRateForCountry($taxCountry);
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account', 'href' => route('shop.account')],
            ['label' => 'Orders', 'href' => route('shop.account.orders')],
            ['label' => $order->number],
        ]" />

        <div class="mt-4 mb-6 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">Order {{ $order->number }}</h1>
            <x-shop.status-pill :status="$order->status" />
        </div>

        <ul class="divide-y max-w-full" style="border-color: var(--color-border);">
            @foreach ($order->items as $item)
                <li class="py-3 flex flex-wrap justify-between gap-3">
                    <div>
                        <div class="font-semibold">{{ $item->product_name_snapshot }}</div>
                        <div class="text-sm" style="color: var(--color-text-muted)">
                            {{ $item->variant_label_snapshot }} × {{ $item->qty }}
                        </div>
                        @include('shop.partials.line-personalisation', [
                            'personalisation' => $item->personalisation,
                            'site' => $site,
                            'audience' => 'session',
                        ])
                    </div>
                    <div class="tabular-nums">{{ \App\Support\ShopMoney::format($item->line_total_cents, $currency) }}</div>
                </li>
            @endforeach
        </ul>

        <div class="mt-6 max-w-full sm:max-w-sm sm:ml-auto space-y-1">
            <div class="flex justify-between gap-4">
                <span>Subtotal</span>
                <span class="tabular-nums">{{ \App\Support\ShopMoney::format($order->subtotal_cents, $currency) }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span>Shipping</span>
                <span class="tabular-nums">{{ \App\Support\ShopMoney::format($order->shipping_cents, $currency) }}</span>
            </div>
            @if ($showTaxLine)
                <div class="flex justify-between gap-4" data-testid="checkout-vat">
                    <span>{{ \App\Support\ShopMoney::includesVat($currency) ? 'VAT' : 'Tax' }}</span>
                    <span class="tabular-nums">{{ \App\Support\ShopMoney::format($vatCents, $currency) }}</span>
                </div>
            @endif
            <div class="flex justify-between gap-4 font-semibold">
                <span>Total</span>
                <span class="tabular-nums">{{ \App\Support\ShopMoney::format($order->total_cents, $currency) }}</span>
            </div>
            @unless ($showTaxLine)
                <p class="mt-2 text-sm" data-testid="checkout-tax-note" style="color: var(--color-text-muted);">No sales tax applied.</p>
            @endunless
        </div>

        <section class="mt-8 max-w-md">
            <h2 class="font-semibold mb-2">Shipping address</h2>
            <p style="color: var(--color-text)">
                {{ $address['line1'] ?? '' }}<br>
                @if (! empty($address['line2']))
                    {{ $address['line2'] }}<br>
                @endif
                {{ $address['city'] ?? '' }} {{ $address['postcode'] ?? '' }}
            </p>
        </section>

        @php
            $timeline = \App\Support\Shop\OrderTimeline::for($order);
        @endphp
        <section class="mt-8 max-w-md">
            <h2 class="font-semibold mb-4">Order status</h2>
            <ol class="space-y-0" style="border-left: 2px solid var(--color-border); padding-left: 1.25rem;">
                @foreach ($timeline as $step)
                    <li
                        class="relative pb-5 last:pb-0"
                        data-timeline-step="{{ $step['key'] }}"
                        data-done="{{ $step['done'] ? '1' : '0' }}"
                        style="color: {{ $step['done'] ? 'var(--color-text)' : 'var(--color-text-muted)' }};"
                    >
                        <span
                            aria-hidden="true"
                            class="absolute"
                            style="left: -1.25rem; top: 0.35rem; width: 0.65rem; height: 0.65rem; border-radius: 9999px; transform: translateX(-50%); background-color: {{ $step['done'] ? 'var(--color-primary)' : 'var(--color-border)' }};"
                        ></span>
                        <div class="font-semibold">{{ $step['label'] }}</div>
                        @if ($step['at'])
                            <div class="text-sm">{{ $step['at']->timezone(config('app.timezone'))->format('d M Y') }}</div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-shop.layout>
