@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account', 'href' => route('shop.account')],
            ['label' => 'Enquiries'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-6">Enquiries</h1>

        @if ($enquiries->isEmpty())
            <p style="color: var(--color-text-muted)">You have no enquiries yet.</p>
        @else
            <ul class="divide-y max-w-xl" style="border-color: var(--color-border);">
                @foreach ($enquiries as $enquiry)
                    <li class="py-4">
                        <div class="font-semibold">{{ ($enquiry->payload['kind'] ?? null) === 'quote' ? 'Quote request' : ($enquiry->payload['product'] ?? 'Enquiry') }}</div>
                        <div class="text-sm mt-1" style="color: var(--color-text-muted)">
                            {{ $enquiry->created_at->format('d M Y') }}
                            · {{ $enquiry->status }}
                        </div>
                        @include('shop.partials.quote-enquiry-lines', ['enquiry' => $enquiry])
                        @if (! empty($enquiry->payload['message']))
                            <p class="mt-2">{{ $enquiry->payload['message'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-shop.layout>
