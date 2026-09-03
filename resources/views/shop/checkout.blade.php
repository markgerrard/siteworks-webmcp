@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $currency = $site->shop_currency ?? 'GBP';
    $fieldStyle = 'width: 100%; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);';
    $orderTotal = \App\Support\ShopMoney::format($total_cents, $currency);
    $summaryCardStyle = 'border: 1px solid var(--color-border);';
    $shopCountryCode = $site->shopCountryCode();
    $postcodeLabel = $shopCountryCode === 'US' ? 'ZIP code' : 'Postcode';
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Cart', 'href' => url('/shop/cart')],
            ['label' => 'Checkout'],
        ]" />

        <x-shop.steps current="details" class="mb-6" />

        <h1 class="text-2xl font-bold mb-6">Checkout</h1>

        <details class="mb-6 rounded p-4 md:hidden" style="{{ $summaryCardStyle }}">
            <summary class="cursor-pointer font-medium">Order summary · {{ $orderTotal }}</summary>
            <div class="mt-3" data-testid="checkout-summary">
                @include('shop.partials.checkout-summary')
            </div>
        </details>
        <details class="mb-6 rounded p-4 hidden md:block lg:hidden" open style="{{ $summaryCardStyle }}">
            <summary class="cursor-pointer font-medium">Order summary · {{ $orderTotal }}</summary>
            <div class="mt-3" data-testid="checkout-summary">
                @include('shop.partials.checkout-summary')
            </div>
        </details>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <div class="min-w-0" x-data>
                @unless ($signedIn)
                    <div class="mb-6 w-full rounded p-3" style="border: 1px solid var(--color-border); background-color: var(--color-surface-alt);">
                        <p class="font-medium">Have an account? <a
                            href="/shop/account/login?return=/shop/checkout"
                            class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            style="color: var(--color-text); text-decoration: underline; text-underline-offset: 0.2em; outline-color: var(--color-accent);"
                        >Sign in</a> for a faster checkout</p>
                    </div>
                @endunless

                @if ($fulfilmentActive ?? false)
                    <form method="GET" action="{{ url('/shop/checkout') }}" class="mb-4 space-y-2" data-testid="fulfilment-method-form" x-data x-init="$refs.fulfilmentMethodUpdate.classList.add('hidden')">
                        @if (($checkoutPostcode ?? '') !== '')
                            <input type="hidden" name="postcode" value="{{ $checkoutPostcode }}">
                        @endif
                        <fieldset>
                            <legend class="text-sm font-medium mb-2">How should we get this to you?</legend>
                            @foreach ($fulfilmentMethods as $method)
                                @php
                                    $deliveryLocked = $method === 'delivery' && ! ($deliveryZoneMatched ?? false);
                                @endphp
                                <label class="flex items-center gap-2 py-1">
                                    <input
                                        type="radio"
                                        name="fulfilment_method"
                                        value="{{ $method }}"
                                        @checked($selectedFulfilmentMethod === $method)
                                        @disabled($deliveryLocked)
                                        @change="$el.form.submit()"
                                    >
                                    <span>{{ $fulfilmentLabels[$method] ?? $method }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                        @if (! ($deliveryZoneMatched ?? false) && in_array('delivery', $fulfilmentMethods, true))
                            <p class="text-sm" data-testid="fulfilment-delivery-pending" style="color: var(--color-text-muted);">Enter your postcode to see delivery</p>
                        @endif
                        <button type="submit" x-ref="fulfilmentMethodUpdate" data-testid="fulfilment-method-update" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="min-height: 44px; padding: 0.5rem 1rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);">Update</button>
                    </form>
                    @error('fulfilment_method')
                        <p class="mb-3 text-sm" role="alert" data-testid="fulfilment-method-error">{{ $message }}</p>
                    @enderror
                @endif

                <form method="POST" action="/shop/checkout/start" class="space-y-3 w-full">
                    @csrf
                    @if ($fulfilmentActive ?? false)
                        <input type="hidden" name="fulfilment_method" value="{{ $selectedFulfilmentMethod }}">
                    @endif

                    <label class="block">
                        <span class="text-sm font-medium">Full name</span>
                        <input name="name" value="{{ old('name', $prefill['name'] ?? '') }}" required autocomplete="name" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Email</span>
                        <input name="email" type="email" value="{{ old('email', $prefill['email'] ?? '') }}" required autocomplete="email" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Phone (optional)</span>
                        <input name="phone" type="tel" value="{{ old('phone', $prefill['phone'] ?? '') }}" autocomplete="tel" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
                    </label>

                    @if ($signedIn)
                        <details class="rounded p-3" style="border: 1px solid var(--color-border);">
                            <summary class="cursor-pointer font-medium">Use a different address</summary>
                            <div class="mt-3 space-y-3">
                    @endif
                    <label class="block">
                        <span class="text-sm font-medium">Address line 1</span>
                        <input name="line1" value="{{ old('line1', $prefill['line1'] ?? '') }}" required autocomplete="address-line1" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Address line 2</span>
                        <input name="line2" value="{{ old('line2', $prefill['line2'] ?? '') }}" autocomplete="address-line2" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-sm font-medium">City</span>
                            <input name="city" value="{{ old('city', $prefill['city'] ?? '') }}" required autocomplete="address-level2" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">{{ $postcodeLabel }}</span>
@if($fulfilmentActive ?? false)
                            <input name="postcode" data-testid="checkout-postcode" value="{{ old('postcode', $addressPostcode ?? '') }}" required autocomplete="postal-code" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}" @change="const u = new URL('{{ url('/shop/checkout') }}'); u.searchParams.set('postcode', $el.value); @if ($selectedFulfilmentMethod) u.searchParams.set('fulfilment_method', '{{ $selectedFulfilmentMethod }}'); @endif window.location.assign(u.href)">
@else
                            <input name="postcode" value="{{ old('postcode', $prefill['postcode'] ?? '') }}" required autocomplete="postal-code" class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }}">
@endif
                            @error('postcode')
                                <p class="mt-1 text-sm" role="alert" data-testid="checkout-postcode-error">{{ $message }}</p>
                            @enderror
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-sm font-medium">Country</span>
                        <input name="country_code" value="{{ old('country_code', $prefill['country_code'] ?? $shopCountryCode) }}" readonly class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $fieldStyle }} background-color: var(--color-surface-alt);">
                    </label>
                    @if ($signedIn)
                            </div>
                        </details>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="save_address" value="1" @checked(old('save_address', ! $hasDefaultShipping))>
                            <span>Save this address</span>
                        </label>
                    @endif

                    <button
                        type="submit"
                        class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                    >Pay with Stripe</button>
                </form>
            </div>

            <aside class="hidden lg:block lg:sticky lg:top-6" aria-label="Order summary" style="top: calc(var(--site-header-height, 4rem) + 1.5rem);">
                <div class="rounded p-4" data-testid="checkout-summary" style="{{ $summaryCardStyle }}">
                    @include('shop.partials.checkout-summary')
                </div>
            </aside>
        </div>
    </div>
</x-shop.layout>
