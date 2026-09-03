@php
    $block = is_array($enquiry->payload['fulfilment'] ?? null) ? $enquiry->payload['fulfilment'] : null;
@endphp
@if ($block)
    <p class="mt-2 text-sm" data-testid="quote-fulfilment">
        @if (! empty($block['label']))
            {{ $block['label'] }}
        @endif
        @if (! empty($block['postcode']))
            @if (! empty($block['label'])) · @endif{{ $block['postcode'] }}
        @endif
        @if (! empty($block['zone_name']))
            · {{ $block['zone_name'] }}
        @endif
    </p>
@endif
