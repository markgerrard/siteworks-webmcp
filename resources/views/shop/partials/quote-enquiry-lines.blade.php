@php
    $quoteLines = ($enquiry->payload['kind'] ?? null) === 'quote' && is_array($enquiry->payload['lines'] ?? null)
        ? $enquiry->payload['lines']
        : [];
@endphp
@if ($quoteLines !== [])
    <ul class="{{ $listClass ?? 'mt-2 space-y-1 text-sm' }}">
        @foreach ($quoteLines as $line)
            @php
                $lineName = is_array($line) ? (string) ($line['name'] ?? '') : '';
                $lineVariant = is_array($line) ? trim((string) ($line['variant_label'] ?? '')) : '';
                $lineQty = is_array($line) ? (int) ($line['qty'] ?? 0) : 0;
                $lineCents = is_array($line) ? (int) ($line['unit_price_cents'] ?? 0) : 0;
                $lineCurrency = is_array($line) ? (string) ($line['currency'] ?? 'GBP') : 'GBP';
            @endphp
            <li>
                {{ $lineName }}
                @if ($lineVariant !== '')
                    — {{ $lineVariant }}
                @endif
                · × {{ $lineQty }}
                · {{ \App\Support\ShopMoney::format($lineCents, $lineCurrency) }}
                @include('shop.partials.line-personalisation', [
                    'personalisation' => is_array($line) ? ($line['personalisation'] ?? null) : null,
                    'site' => $enquiry->site,
                    'audience' => $mailAudience ?? 'session', // only the mail template passes 'mail'
                ])
            </li>
        @endforeach
    </ul>
@endif
