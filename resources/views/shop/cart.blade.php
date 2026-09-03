@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $currency = $site->shop_currency ?? 'GBP';
    $showTaxLine = \App\Support\ShopMoney::includesVat($currency)
        || (int) $vat_cents !== 0
        || ($tax_rate_exists ?? false);
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Cart'],
        ]" />
        <h1 class="text-2xl font-bold mb-6">Your cart</h1>

        @if (($site->shop_mode ?? 'cart') === 'quote' && session('status'))
            <p role="status" class="mb-4">{{ session('status') }}</p>
        @endif

        @if ($cart->items->isEmpty())
            <x-shop.empty-state />
        @else
            <ul class="divide-y max-w-full" style="border-color: var(--color-border);">
                @foreach ($cart->items as $item)
                    @php
                        $productName = $item->variant->product->name;
                        $thumb = $item->variant->product->images->first();
                        $variantLabel = $item->variant->shopperFacingLabel();
                    @endphp
                    <li class="py-4 flex flex-wrap gap-4 items-start max-w-full">
                        <div
                            class="shrink-0 overflow-hidden"
                            style="width: 5rem; aspect-ratio: 1 / 1; border-radius: var(--radius-card); background-color: var(--color-surface-alt);"
                        >
                            @if ($thumb)
                                <img
                                    src="{{ $thumb->url('thumb') }}"
                                    alt="{{ $productName }}"
                                    width="80"
                                    height="80"
                                    class="w-full h-full object-cover"
                                    style="aspect-ratio: 1 / 1;"
                                >
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="font-semibold">{{ $productName }}</div>
                            <div class="text-sm" style="color: var(--color-text-muted)">
                                @if ($variantLabel !== ''){{ $variantLabel }} · @endif<x-shop.price :amount="\App\Support\ShopMoney::format($item->unit_price_cents, $currency)" />
                            </div>
                            @include('shop.partials.line-personalisation', [
                                'personalisation' => $item->personalisation,
                                'site' => $site,
                                'editable' => true,
                                'itemId' => $item->id,
                                'editInputs' => \App\Services\Shop\LinePersonalisation::definitionsFromFrozen($item->personalisation),
                            ])

                            <form method="POST" action="/shop/cart/{{ $item->id }}" class="mt-3 flex flex-wrap items-center gap-3">
                                @csrf
                                @method('PATCH')
                                <label class="block">
                                    <span class="text-sm font-medium">Quantity</span>
                                    <span class="mt-1 block">
                                        <x-shop.qty-stepper :value="$item->qty" />
                                    </span>
                                </label>
                                <button
                                    type="submit"
                                    class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                    style="min-height: 44px; min-width: 44px; padding: 0.5rem 1rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: transparent; outline-color: var(--color-accent);"
                                >Update</button>
                            </form>
                        </div>

                        <form method="POST" action="/shop/cart/{{ $item->id }}" class="shrink-0">
                            @csrf
                            @method('DELETE')
                            <button
                                type="submit"
                                aria-label="Remove {{ $productName }}"
                                class="inline-flex items-center gap-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="min-width: 44px; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); background-color: transparent; border-radius: var(--radius-button); outline-color: var(--color-accent);"
                            >
                                <i data-lucide="trash-2" class="w-4 h-4" aria-hidden="true"></i>
                                Remove
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6 max-w-full sm:max-w-sm sm:ml-auto" data-testid="cart-totals">
                <div class="flex justify-between py-1 gap-4" data-testid="cart-subtotal">
                    <span>Subtotal</span>
                    <span>{{ \App\Support\ShopMoney::format($subtotal_cents, $currency) }}</span>
                </div>
@php
    $cartShippingHeading = $shipping_heading ?? ('Shipping'.($shipping_label ? ' — '.$shipping_label : ''));
    $cartShippingAmountHtml = ($show_shipping_amount ?? true)
        ? "\n                    <span>".e(\App\Support\ShopMoney::format($shipping_cents, $currency)).'</span>'
        : '';
@endphp
                <div class="flex justify-between py-1 gap-4" data-testid="cart-shipping">
                    <span>{{ $cartShippingHeading }}</span>{!! $cartShippingAmountHtml !!}
                </div>
@if(! empty($minimum_order_message))<p class="py-1 text-sm" data-testid="cart-minimum-order" style="color: var(--color-text-muted);">{{ $minimum_order_message }}</p>
@endif
@if(! empty($collect_address))<p class="py-1 text-sm" data-testid="cart-collect-address" style="color: var(--color-text-muted);">Collect from {{ $collect_address }}</p>
@endif
                @if ($showTaxLine)
                    <div class="flex justify-between py-1 gap-4" data-testid="cart-vat">
                        <span>{{ \App\Support\ShopMoney::includesVat($currency) ? 'VAT (included)' : 'Tax' }}</span>
                        <span>{{ \App\Support\ShopMoney::format($vat_cents, $currency) }}</span>
                    </div>
                @endif
                <div class="flex justify-between py-2 gap-4 font-semibold" data-testid="cart-total" style="border-top: 1px solid var(--color-border);">
                    <span>Total</span>
                    <span>{{ \App\Support\ShopMoney::format($total_cents, $currency) }}</span>
                </div>
                @unless ($showTaxLine)
                    <p class="mt-2 text-sm" data-testid="checkout-tax-note" style="color: var(--color-text-muted);">No sales tax applied.</p>
                @endunless

@php
    $fulfilmentWidget = app(\App\Services\Shop\Fulfilment\FulfilmentService::class)->widgetState($site, request());
@endphp
@if($fulfilmentWidget)@include('shop.partials.fulfilment-widget', ['widget' => $fulfilmentWidget])@endif
                @if (($site->shop_mode ?? 'cart') === 'quote')
                    <a
                        href="/shop/quote"
                        class="mt-4 inline-flex items-center justify-center w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                    >Request a quote</a>
                @else
                    <a
                        href="/shop/checkout"
                        class="mt-4 inline-flex items-center justify-center w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                    >Checkout</a>
                @endif
            </div>
        @endif
    </div>
</x-shop.layout>
