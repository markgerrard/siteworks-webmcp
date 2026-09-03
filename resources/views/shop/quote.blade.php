@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $currency = $site->shop_currency ?? 'GBP';
    $fieldStyle = 'width: 100%; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);';
    $summaryCardStyle = 'border: 1px solid var(--color-border);';
    $quoteLines = $cart->items->map(function ($item) use ($currency): array {
        $product = $item->variant->product;
        $thumb = $product->images->first();
        $qty = (int) $item->qty;

        return [
            'name' => $product->name,
            'variant_label' => $item->variant->shopperFacingLabel(),
            'qty' => $qty,
            'line_cents' => (int) $item->unit_price_cents * $qty,
            'currency' => $currency,
            'thumb_url' => $thumb?->url('thumb'),
            'personalisation' => $item->personalisation,
        ];
    });
    $guideTotal = \App\Support\ShopMoney::formatWithVat((int) $quoteLines->sum('line_cents'), $currency);
    $messageLength = strlen((string) old('message', ''));
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Cart', 'href' => url('/shop/cart')],
            ['label' => 'Request a quote'],
        ]" />
        <h1 class="text-2xl font-bold mb-2">Request a quote</h1>
        <p class="mb-6" style="color: var(--color-text-muted)">Tell us who it's for and when you need it — we'll come back with a price and availability.</p>

        <details class="mb-6 rounded p-4 md:hidden" style="{{ $summaryCardStyle }}">
            <summary class="cursor-pointer font-medium">Your list · {{ $guideTotal }}</summary>
            <div class="mt-3" data-testid="quote-summary-sm">
                @include('shop.partials.quote-summary')
            </div>
        </details>
        <details class="mb-6 rounded p-4 hidden md:block lg:hidden" open style="{{ $summaryCardStyle }}">
            <summary class="cursor-pointer font-medium">Your list · {{ $guideTotal }}</summary>
            <div class="mt-3" data-testid="quote-summary-md">
                @include('shop.partials.quote-summary')
            </div>
        </details>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <div class="min-w-0">
                @if (session('status'))
                    <p role="status" class="mb-4 rounded p-3 text-sm" data-testid="quote-status" style="border: 1px solid var(--color-border); background-color: var(--color-surface-alt);">{{ session('status') }}</p>
                @endif

                <h2 class="font-semibold mb-4">Your details</h2>

                {{-- Revealed only when the browser exposes a model context; the tools that fill
                     the form are registered from the script at the foot of this view. --}}
                <p
                    data-webmcp-quote-hint
                    data-testid="quote-agent-hint"
                    hidden
                    class="mb-4 flex items-start gap-2 rounded p-3 text-sm"
                    style="border: 1px solid var(--color-border); background-color: var(--color-surface-alt); color: var(--color-text-on-alt); border-radius: var(--radius-card);"
                >
                    <i data-lucide="sparkles" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" style="color: var(--color-accent-text-on-alt);"></i>
                    <span>An AI agent in your browser can fill this form for you. Check what it wrote, then press <strong>Request a quote</strong> yourself — nothing is sent until you do.</span>
                </p>

                <form method="POST" action="{{ url('/shop/quote') }}" class="space-y-3 w-full" data-webmcp-quote-form data-business-name="{{ $site->business_name }}">
                    @csrf
                    <input type="hidden" name="quote_token" value="{{ $quoteToken }}">
                    <input type="text" name="{{ $honeypotField }}" tabindex="-1" autocomplete="off"
                           style="position: absolute; left: -9999px;" aria-hidden="true">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-sm font-medium">Name <span aria-hidden="true">*</span></span>
                            <input
                                type="text"
                                name="name"
                                required
                                maxlength="120"
                                autocomplete="name"
                                value="{{ old('name', $prefillName) }}"
                                class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="{{ $fieldStyle }}"
                            >
                            @error('name')
                                <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
                            @enderror
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">Email <span aria-hidden="true">*</span></span>
                            <input
                                type="email"
                                name="email"
                                required
                                maxlength="255"
                                autocomplete="email"
                                inputmode="email"
                                value="{{ old('email', $prefillEmail) }}"
                                class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="{{ $fieldStyle }}"
                            >
                            @error('email')
                                <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
                            @enderror
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-sm font-medium">Phone</span>
                            <input
                                type="tel"
                                name="phone"
                                maxlength="64"
                                autocomplete="tel"
                                value="{{ old('phone', $prefillPhone) }}"
                                class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="{{ $fieldStyle }}"
                            >
                            @error('phone')
                                <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
                            @enderror
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium">When do you need it?</span>
                            <input
                                type="date"
                                name="needed_by"
                                min="{{ now()->toDateString() }}"
                                value="{{ old('needed_by') }}"
                                class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="{{ $fieldStyle }}"
                            >
                            @error('needed_by')
                                <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
                            @enderror
                        </label>
                    </div>

                    @foreach ($quoteExtraFields ?? [] as $extraField)
                        @php
                            $extraName = $extraField['name'];
                            $extraType = $extraField['type'] === 'textarea' ? 'textarea' : ($extraField['type'] === 'date' ? 'date' : ($extraField['type'] === 'number' ? 'number' : 'text'));
                        @endphp
                        <label class="block">
                            <span class="text-sm font-medium">{{ $extraField['label'] }}</span>
                            @if ($extraType === 'textarea')
                                <textarea
                                    name="{{ $extraName }}"
                                    rows="3"
                                    maxlength="1000"
                                    class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                    style="{{ $fieldStyle }}"
                                >{{ old($extraName) }}</textarea>
                            @else
                                <input
                                    type="{{ $extraType }}"
                                    name="{{ $extraName }}"
                                    @if ($extraType === 'date') min="{{ now()->toDateString() }}" @endif
                                    @if ($extraType === 'number') min="1" max="9999" inputmode="numeric" @endif
                                    value="{{ old($extraName) }}"
                                    class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                    style="{{ $fieldStyle }}"
                                >
                            @endif
                            @error($extraName)
                                <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
                            @enderror
                        </label>
                    @endforeach

                    @if ($fulfilmentActive ?? false)
                        <fieldset class="space-y-2" data-testid="quote-fulfilment-fields">
                            <legend class="text-sm font-medium">How should we get this to you?</legend>
                            @foreach ($fulfilmentMethods as $method)
                                <label class="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="fulfilment_method"
                                        value="{{ $method }}"
                                        @checked($selectedFulfilmentMethod === $method)
                                    >
                                    <span>{{ $fulfilmentLabels[$method] ?? $method }}</span>
                                </label>
                            @endforeach
                            <label class="block">
                                <span class="text-sm font-medium">Delivery postcode</span>
                                <input
                                    type="text"
                                    name="fulfilment_postcode"
                                    value="{{ $prefillPostcode }}"
                                    maxlength="16"
                                    autocomplete="postal-code"
                                    class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                    style="{{ $fieldStyle }}"
                                >
                            </label>
                        </fieldset>
                    @endif

                    <label class="block" x-data="{ count: {{ $messageLength }} }">
                        <span class="text-sm font-medium">Message</span>
                        <textarea
                            name="message"
                            rows="4"
                            maxlength="1000"
                            x-on:input="count = $event.target.value.length"
                            class="mt-1 w-full max-w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            style="{{ $fieldStyle }}"
                        >{{ old('message') }}</textarea>
                        <p class="mt-1 text-sm tabular-nums" data-testid="quote-message-count" style="color: var(--color-text-muted)"><span x-text="count">{{ $messageLength }}</span>/1000</p>
                        @error('message')
                            <p class="mt-1 text-sm" role="alert">{{ $message }}</p>
                        @enderror
                    </label>

                    <button
                        type="submit"
                        class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                    >Request a quote</button>
                    <p class="text-sm" style="color: var(--color-text-muted);">We reply within one business day.</p>
                </form>
            </div>

            <aside class="hidden lg:block lg:sticky lg:top-6" aria-label="Your list" style="top: calc(var(--site-header-height, 4rem) + 1.5rem);">
                <div class="rounded p-4" data-testid="quote-summary" style="{{ $summaryCardStyle }}">
                    @include('shop.partials.quote-summary')
                </div>
            </aside>
        </div>
    </div>

    <style>
        /* Fields an agent has just written into: a brief accent ring so the shopper can see
           what to check. Under reduced motion the ring still shows, it just does not fade. */
        [data-webmcp-quote-form] input,
        [data-webmcp-quote-form] textarea {
            transition: box-shadow 400ms ease-out;
        }
        [data-webmcp-quote-form] [data-agent-filled] {
            box-shadow: 0 0 0 3px var(--color-accent);
        }
        @media (prefers-reduced-motion: reduce) {
            [data-webmcp-quote-form] input,
            [data-webmcp-quote-form] textarea { transition: none; }
        }
    </style>
    <script>
{!! preg_replace('/^export /m', '', (string) file_get_contents(resource_path('js/shop/webmcp-quote.js'))) !!}
bootQuoteFormTools();
    </script>
</x-shop.layout>
