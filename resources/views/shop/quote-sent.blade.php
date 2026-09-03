@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $currency = $site->shop_currency ?? 'GBP';
    $summaryCardStyle = 'border: 1px solid var(--color-border);';
    $rawLines = ($enquiry && ($enquiry->payload['kind'] ?? null) === 'quote' && is_array($enquiry->payload['lines'] ?? null))
        ? $enquiry->payload['lines']
        : [];
    $quoteLines = collect($rawLines)->map(function (array $line) use ($currency): array {
        $qty = (int) ($line['qty'] ?? 0);
        $unitCents = (int) ($line['unit_price_cents'] ?? 0);

        return [
            'name' => (string) ($line['name'] ?? ''),
            'variant_label' => (string) ($line['variant_label'] ?? ''),
            'qty' => $qty,
            'line_cents' => $unitCents * $qty,
            'currency' => (string) ($line['currency'] ?? $currency),
            'thumb_url' => null,
            'personalisation' => $line['personalisation'] ?? null,
        ];
    });
    $guideTotal = \App\Support\ShopMoney::formatWithVat((int) $quoteLines->sum('line_cents'), $currency);
    $hasList = $quoteLines->isNotEmpty();
    $showEditList = false;
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Request sent'],
        ]" />

        @if ($hasList)
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
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold mb-2">Request sent</h1>

                @if ($enquiry)
                    <p class="mb-3">Reference {{ $enquiry->id }}</p>
                    <p class="mb-6" style="color: var(--color-text-muted);">We'll reply to {{ $enquiry->email }}</p>
                    @include('shop.partials.quote-fulfilment', ['enquiry' => $enquiry])
                @else
                    <p class="mb-6" style="color: var(--color-text-muted);">Thanks — we'll be in touch.</p>
                @endif

                <a
                    href="/shop"
                    class="inline-flex items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="min-height: 44px; min-width: 44px; padding: 0.75rem 1.25rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                >Back to shop</a>
            </div>

            @if ($hasList)
                <aside class="hidden lg:block lg:sticky lg:top-6" aria-label="Your list" style="top: calc(var(--site-header-height, 4rem) + 1.5rem);">
                    <div class="rounded p-4" data-testid="quote-summary" style="{{ $summaryCardStyle }}">
                        @include('shop.partials.quote-summary')
                    </div>
                </aside>
            @endif
        </div>
    </div>
</x-shop.layout>
