<h1>New order — {{ $order->number }}</h1>
<p>{{ $order->name }} placed an order totalling {{ \App\Support\ShopMoney::format((int) $order->total_cents, $order->site?->shop_currency ?? 'GBP') }}.</p>
<p><a href="{{ config('app.url') }}/admin/sites/{{ $order->site_id }}/shop/orders/{{ $order->id }}">View order</a></p>
@foreach ($order->items as $item)
    @include('shop.partials.line-personalisation', [
        'personalisation' => $item->personalisation,
        'site' => $order->site,
        'audience' => 'mail',
    ])
@endforeach
