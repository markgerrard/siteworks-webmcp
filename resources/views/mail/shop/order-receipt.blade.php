<h1>Thanks for your order, {{ $order->name }}!</h1>
<p>Order number: <strong>{{ $order->number }}</strong></p>

<table width="100%" cellpadding="6" style="border-collapse:collapse;border:1px solid #ddd">
    <thead><tr><th align="left">Item</th><th align="right">Qty</th><th align="right">Total</th></tr></thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>
                {{ $item->product_name_snapshot }} @if ($item->variant_label_snapshot) — {{ $item->variant_label_snapshot }} @endif
                @include('shop.partials.line-personalisation', [
                    'personalisation' => $item->personalisation,
                    'site' => $order->site,
                    'audience' => 'mail',
                ])
            </td>
            <td align="right">{{ $item->qty }}</td>
            <td align="right">{{ \App\Support\ShopMoney::format((int) $item->line_total_cents, $order->site?->shop_currency ?? 'GBP') }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr><td></td><td align="right">Subtotal</td><td align="right">{{ \App\Support\ShopMoney::format((int) $order->subtotal_cents, $order->site?->shop_currency ?? 'GBP') }}</td></tr>
        <tr><td></td><td align="right">Shipping ({{ $order->shipping_method_label }})</td><td align="right">{{ \App\Support\ShopMoney::format((int) $order->shipping_cents, $order->site?->shop_currency ?? 'GBP') }}</td></tr>
        <tr><td></td><td align="right"><strong>Total</strong></td><td align="right"><strong>{{ \App\Support\ShopMoney::format((int) $order->total_cents, $order->site?->shop_currency ?? 'GBP') }}</strong></td></tr>
    </tfoot>
</table>

@if (! $order->customer_id)
    @php $claimUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('shop.account.claim', now()->addDays(30), ['order' => $order->id, 'email' => $order->email]); @endphp
    <hr>
    <p>Want to track this order and speed up future purchases? <a href="{{ $claimUrl }}">Create an account</a>.</p>
@endif
