@php
    $rows = \App\Services\Shop\LinePersonalisation::displayRows($personalisation ?? null);
    $site = $site ?? null;
    $images = app(\App\Services\Shop\PersonalisationImageStore::class);
    $audience = $audience ?? 'session';
    $ttl = $audience === 'mail'
        ? (int) config('shop_input_presets.mail_signed_url_ttl_days', 7) * 86400
        : (int) config('shop_input_presets.signed_url_ttl_seconds', 300);
    $editable = $editable ?? false;
    $itemId = $itemId ?? null;
@endphp
@if ($rows !== [])
    <ul class="mt-1 space-y-1 text-sm" style="color: var(--color-text-muted);" data-line-personalisation>
        @foreach ($rows as $row)
            <li>
                <span>{{ $row['label'] }}:</span>
                @if ($row['kind'] === 'image')
                    <span class="inline-flex flex-wrap gap-1 align-middle">
                        @foreach ($row['images'] as $image)
                            @php
                                $url = $site
                                    ? $images->signedUrl($site, $image['path'], $ttl, $audience)
                                    : '';
                            @endphp
                            @if ($url !== '')
                                <a href="{{ $url }}" target="_blank" rel="noopener">
                                    <img src="{{ $url }}" alt="{{ $image['name'] }}" width="40" height="40" class="object-cover" style="width: 40px; height: 40px; border-radius: var(--radius-card);">
                                </a>
                                <a href="{{ $url }}" download="{{ $image['name'] }}" class="text-xs underline">Download</a>
                            @endif
                        @endforeach
                    </span>
                @else
                    <span title="{{ $row['title'] }}">{{ $row['display'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($editable && $itemId)
        <details class="mt-1">
            <summary class="text-sm underline cursor-pointer" style="min-height: 44px;">Edit</summary>
            <form method="POST" action="/shop/cart/{{ $itemId }}/personalisation" enctype="multipart/form-data" class="mt-2 space-y-2">
                @csrf
                @method('PATCH')
                @include('shop.partials.customer-inputs-form', [
                    'inputs' => $editInputs ?? [],
                    'values' => collect($personalisation ?? [])->mapWithKeys(fn (mixed $entry, string|int $slug): array => [
                        (string) $slug => is_array($entry) ? ($entry['value'] ?? null) : null,
                    ])->all(),
                ])
                <button
                    type="submit"
                    class="text-sm underline"
                    style="background: transparent; min-height: 44px; color: var(--color-text);"
                >Save</button>
            </form>
        </details>
    @endif
@endif
