@props(['amount', 'currency' => null, 'vat' => null])

<span {{ $attributes->merge(['class' => 'tabular-nums whitespace-nowrap']) }} style="font-variant-numeric: tabular-nums">{{ $amount }}@if ($vat ?? \App\Support\ShopMoney::includesVat($currency ?? 'GBP')) <span style="font-variant-caps: small-caps">inc. VAT</span>@endif</span>
