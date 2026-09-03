@php
    $currency = $currency ?? ($site->shop_currency ?? 'GBP');
    $showTaxLine = \App\Support\ShopMoney::includesVat($currency)
        || (int) $vat_cents !== 0
        || ($tax_rate_exists ?? false);
@endphp
<ul class="divide-y max-w-full" style="border-color: var(--color-border);">
    @foreach ($cart->items as $item)
        @php
            $product = $item->variant->product;
            $productName = $product->name;
            $thumb = $product->images->first();
            $variantLabel = $item->variant->shopperFacingLabel();
            $lineCents = (int) $item->unit_price_cents * (int) $item->qty;
            $linePrice = \App\Support\ShopMoney::includesVat($currency)
                ? \App\Support\ShopMoney::formatWithVat($lineCents, $currency)
                : \App\Support\ShopMoney::format($lineCents, $currency);
        @endphp
        <li class="py-4 flex flex-wrap gap-4 items-start max-w-full">
            <div
                class="shrink-0 overflow-hidden"
                style="width: 64px; height: 64px; border-radius: var(--radius-card); background-color: var(--color-surface-alt);"
            >
                @if ($thumb)
                    <img
                        src="{{ $thumb->url('thumb') }}"
                        alt="{{ $productName }}"
                        width="64"
                        height="64"
                        class="w-full h-full object-cover"
                    >
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <div class="font-semibold">{{ $productName }}</div>
                @if ($variantLabel !== '')
                    <div class="text-sm" style="color: var(--color-text-muted)">{{ $variantLabel }}</div>
                @endif
                <div class="text-sm" style="color: var(--color-text-muted)">× {{ $item->qty }}</div>
                @include('shop.partials.line-personalisation', [
                    'personalisation' => $item->personalisation,
                    'site' => $site,
                    'audience' => 'session',
                ])
            </div>
            <div class="tabular-nums shrink-0">{{ $linePrice }}</div>
        </li>
    @endforeach
</ul>

<a
    href="/shop/cart"
    class="inline-block mt-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
    style="color: var(--color-text); text-decoration: underline; text-underline-offset: 0.2em; outline-color: var(--color-accent);"
>Edit cart</a>

<div class="mt-4">
    <div class="flex justify-between py-1 gap-4" data-testid="checkout-subtotal">
        <span>Subtotal</span>
        <span>{{ \App\Support\ShopMoney::format($subtotal_cents, $currency) }}</span>
    </div>
@if(! empty($pricing_from_widget) && ($widget_postcode_display ?? '') !== '')<p class="py-1 text-sm" data-testid="checkout-postcode-basis" style="color: var(--color-text-muted);">Based on {{ $widget_postcode_display }} — confirm your address</p>
@endif
    @if ($delivery_pending ?? false)
        <div class="flex justify-between py-1 gap-4" data-testid="checkout-shipping">
            <span>Enter your postcode to see delivery</span>
        </div>
    @elseif ($shipping_visible ?? true)
        <div class="flex justify-between py-1 gap-4" data-testid="checkout-shipping">
            <span>Shipping{{ $shipping_label ? ' — '.$shipping_label : '' }}</span>
            <span>
                @if ((int) $shipping_cents === 0 && filled($shipping_label))
                    Free
                @else
                    {{ \App\Support\ShopMoney::format($shipping_cents, $currency) }}
                @endif
            </span>
        </div>
    @endif
    @if (! empty($minimum_order_message))
        <p class="py-1 text-sm" data-testid="checkout-minimum-order" style="color: var(--color-text-muted);">{{ $minimum_order_message }}</p>
    @endif
    @if (! empty($collect_address))
        <p class="py-1 text-sm" data-testid="checkout-collect-address" style="color: var(--color-text-muted);">Collect from {{ $collect_address }}</p>
    @endif
    @if ($showTaxLine)
        <div class="flex justify-between py-1 gap-4" data-testid="checkout-vat">
            <span>{{ \App\Support\ShopMoney::includesVat($currency) ? 'VAT (included)' : 'Tax' }}</span>
            <span>{{ \App\Support\ShopMoney::format($vat_cents, $currency) }}</span>
        </div>
    @endif
    <div class="mt-2 flex justify-between gap-4 pt-2 font-semibold text-lg" data-testid="checkout-total" style="border-top: 1px solid var(--color-border);">
        <span>Total</span>
        <span>{{ \App\Support\ShopMoney::format($total_cents, $currency) }}</span>
    </div>
    @unless ($showTaxLine)
        <p class="mt-2 text-sm" data-testid="checkout-tax-note" style="color: var(--color-text-muted);">No sales tax applied.</p>
    @endunless
</div>

<div class="mt-4 flex items-center gap-2 text-sm" style="color: var(--color-text-muted)">
    <i data-lucide="lock" class="w-4 h-4 shrink-0" aria-hidden="true"></i>
    <span>Secure checkout with Stripe. We never see your card details.</span>
</div>
