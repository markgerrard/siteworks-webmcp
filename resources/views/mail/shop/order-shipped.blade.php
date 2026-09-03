<h1>Your order {{ $order->number }} is on its way</h1>
@if ($order->tracking_number)
    <p>Tracking: {{ $order->tracking_carrier }} — {{ $order->tracking_number }}</p>
@endif
<p>Thanks for shopping with us.</p>
