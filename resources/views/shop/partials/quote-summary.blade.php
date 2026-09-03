@php
    $currency = $currency ?? ($site->shop_currency ?? 'GBP');
    $showEditList = $showEditList ?? true;
@endphp
<div class="flex justify-between gap-4 items-start">
    <h2 class="font-semibold">Your list</h2>
    @if ($showEditList)
        <a
            href="/shop/cart"
            class="inline-block text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            style="color: var(--color-text); text-decoration: underline; text-underline-offset: 0.2em; outline-color: var(--color-accent);"
        >Edit list →</a>
    @endif
</div>

<ul class="divide-y max-w-full" style="border-color: var(--color-border);">
    @foreach ($quoteLines as $line)
        @php
            $lineName = (string) ($line['name'] ?? '');
            $variantLabel = trim((string) ($line['variant_label'] ?? ''));
            $lineQty = (int) ($line['qty'] ?? 0);
            $lineCents = (int) ($line['line_cents'] ?? 0);
            $lineCurrency = (string) ($line['currency'] ?? $currency);
            $thumbUrl = $line['thumb_url'] ?? null;
        @endphp
        <li class="py-4 flex flex-wrap gap-4 items-start max-w-full">
            <div
                class="shrink-0 overflow-hidden"
                style="width: 64px; height: 64px; border-radius: var(--radius-card); background-color: var(--color-surface-alt);"
            >
                @if ($thumbUrl)
                    <img
                        src="{{ $thumbUrl }}"
                        alt="{{ $lineName }}"
                        width="64"
                        height="64"
                        class="w-full h-full object-cover"
                    >
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <div class="font-semibold">{{ $lineName }}</div>
                @if ($variantLabel !== '')
                    <div class="text-sm" style="color: var(--color-text-muted)">{{ $variantLabel }}</div>
                @endif
                <div class="text-sm" style="color: var(--color-text-muted)">× {{ $lineQty }}</div>
                @include('shop.partials.line-personalisation', [
                    'personalisation' => $line['personalisation'] ?? null,
                    'site' => $site,
                    'audience' => 'session',
                ])
            </div>
            <div class="tabular-nums shrink-0">{{ \App\Support\ShopMoney::formatWithVat($lineCents, $lineCurrency) }}</div>
        </li>
    @endforeach
</ul>

<div class="mt-2 flex justify-between gap-4 pt-2 font-semibold text-lg" data-testid="quote-guide-total" style="border-top: 1px solid var(--color-border);">
    <span>Guide total</span>
    <span>{{ $guideTotal }}</span>
</div>
<p class="mt-2 text-sm" style="color: var(--color-text-muted);">Prices are a guide until we confirm.</p>

<div class="mt-4 flex items-center gap-2 text-sm" style="color: var(--color-text-muted)">
    <i data-lucide="lock" class="w-4 h-4 shrink-0" aria-hidden="true"></i>
    <span>No payment is taken now.</span>
</div>
