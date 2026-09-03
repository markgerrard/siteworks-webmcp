<h1>Refund confirmation — order {{ $order->number }}</h1>
<p>Amount refunded: {{ \App\Support\ShopMoney::format((int) $order->refund_amount_cents, $order->site?->shop_currency ?? 'GBP') }}</p>
