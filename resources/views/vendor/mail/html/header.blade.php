@props(['url'])
@php
    // Pin asset host to the customer domain so the logo loads regardless
    // of which surface dispatched the notification (staff invite from
    // agents domain still emails a customer-facing link, and asset()
    // would otherwise inherit the dispatching request's host).
    $logoUrl = 'https://'.config('domains.customer_domain').'/images/sw-mark.png';
@endphp
<tr>
<td class="header" style="padding: 25px 0; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none; color: #18181b;">
<img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" style="height: 36px; width: auto; vertical-align: middle; margin-right: 12px;">
<span style="font-size: 22px; font-weight: 700; letter-spacing: -0.01em; color: #18181b; vertical-align: middle;">{{ config('app.name') }}</span>
</a>
</td>
</tr>
