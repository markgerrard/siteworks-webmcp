<!doctype html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h2 style="margin: 0 0 4px;">New website enquiry</h2>
    <p style="margin: 0 0 20px; color: #666;">{{ $enquiry->site->business_name }}{{ $enquiry->page_type ? ' — '.$enquiry->page_type.' page' : '' }} · {{ $enquiry->created_at->format('j M Y, H:i') }}</p>

    <table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 8px 12px 8px 0; font-weight: bold; vertical-align: top; white-space: nowrap;">Name</td>
            <td style="padding: 8px 0;">{{ $enquiry->name }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 12px 8px 0; font-weight: bold; vertical-align: top;">Email</td>
            <td style="padding: 8px 0;"><a href="mailto:{{ $enquiry->email }}">{{ $enquiry->email }}</a></td>
        </tr>
        @foreach ($enquiry->payload ?? [] as $field => $value)
            @if (in_array($field, ['lines', 'fulfilment'], true))
                @continue
            @endif
            <tr>
                <td style="padding: 8px 12px 8px 0; font-weight: bold; vertical-align: top; text-transform: capitalize;">{{ str_replace('_', ' ', $field) }}</td>
                <td style="padding: 8px 0; white-space: pre-line;">{{ is_scalar($value) ? $value : json_encode($value) }}</td>
            </tr>
        @endforeach
        @if (($enquiry->payload['kind'] ?? null) === 'quote')
            <tr>
                <td style="padding: 8px 12px 8px 0; font-weight: bold; vertical-align: top;">{{ ($enquiry->field_labels ?? [])['lines'] ?? 'Items' }}</td>
                <td style="padding: 8px 0;">
                    @include('shop.partials.quote-enquiry-lines', ['mailAudience' => 'mail', 'enquiry' => $enquiry])
                </td>
            </tr>
            @if (is_array($enquiry->payload['fulfilment'] ?? null))
                <tr>
                    <td style="padding: 8px 12px 8px 0; font-weight: bold; vertical-align: top;">{{ ($enquiry->field_labels ?? [])['fulfilment'] ?? 'Fulfilment' }}</td>
                    <td style="padding: 8px 0;">
                        @include('shop.partials.quote-fulfilment', ['enquiry' => $enquiry])
                    </td>
                </tr>
            @endif
        @endif
    </table>

    <p style="margin: 24px 0 0; color: #666; font-size: 13px;">
        Reply to this email to respond directly to {{ $enquiry->name }}.
    </p>
</body>
</html>
