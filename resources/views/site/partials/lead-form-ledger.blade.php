@php
    $contact = $profile['contact'] ?? [];
    $geo = $profile['geo'] ?? [];
    $phone = $contact['phones'][0] ?? null;
    $mobile = $contact['mobile'] ?? null;
    $email = $contact['emails'][0] ?? null;
    $address = $contact['address'] ?? null;
    $area = $geo['service_area'] ?? ($geo['primary_area'] ?? null);
    $hours = \App\Support\OpeningHours::rows($profile['opening_hours'] ?? null);
@endphp
@if ($style === 'inline')
@if ($phone || $mobile || $email || $area || $hours !== [])
                <p class="text-xs font-semibold uppercase tracking-[0.18em] opacity-70 mb-3">Prefer to talk?</p>
                @if ($phone)
                <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}" data-ledger-row="phone" class="block text-4xl md:text-5xl font-extrabold leading-none mb-6 hover:opacity-80" style="font-family: var(--font-display, inherit);">{{ $phone }}</a>
                @endif
                <dl class="divide-y" style="{{ $hairline }}">
                    @if ($mobile)<div data-ledger-row="mobile" class="flex justify-between gap-6 py-3"><dt class="opacity-70">Mobile</dt><dd class="font-medium"><a href="tel:{{ preg_replace('/\s+/', '', $mobile) }}">{{ $mobile }}</a></dd></div>@endif
                    @if ($email)<div data-ledger-row="email" class="flex justify-between gap-6 py-3"><dt class="opacity-70">Email</dt><dd class="font-medium"><a href="mailto:{{ $email }}">{{ $email }}</a></dd></div>@endif
                    @if ($area)<div data-ledger-row="area" class="flex justify-between gap-6 py-3"><dt class="opacity-70">Covering</dt><dd class="font-medium text-right">{{ $area }}</dd></div>@endif
                    @if ($hours !== [])<div data-ledger-row="hours" class="py-3"><dt class="opacity-70 mb-1">Hours</dt><dd class="font-medium space-y-0.5">@foreach ($hours as $row)<div class="flex justify-between gap-6"><span>{{ $row['day'] }}</span><span>{{ $row['hours'] }}</span></div>@endforeach</dd></div>@endif
                </dl>
@endif
@elseif ($style === 'stacked')
@if ($phone || $mobile || $email || $address || $area || $hours !== [])
                <dl>
                    @if ($phone || $mobile)
                    <div data-ledger-row="phone" class="py-4 border-t" style="{{ $hairline }}">
                        <dt class="text-xs font-semibold uppercase tracking-[0.18em] opacity-60 mb-1">Phone</dt>
                        <dd class="font-medium">@if ($phone)<a href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a>@endif@if ($phone && $mobile)<span class="inline-block w-6"></span>@endif@if ($mobile)<a href="tel:{{ preg_replace('/\s+/', '', $mobile) }}">{{ $mobile }}</a>@endif</dd>
                    </div>
                    @endif
                    @if ($email)
                    <div data-ledger-row="email" class="py-4 border-t" style="{{ $hairline }}">
                        <dt class="text-xs font-semibold uppercase tracking-[0.18em] opacity-60 mb-1">Email</dt>
                        <dd class="font-medium"><a href="mailto:{{ $email }}">{{ $email }}</a></dd>
                    </div>
                    @endif
                    @if ($address)
                    <div data-ledger-row="address" class="py-4 border-t" style="{{ $hairline }}">
                        <dt class="text-xs font-semibold uppercase tracking-[0.18em] opacity-60 mb-1">Studio</dt>
                        <dd class="font-medium">{{ $address }}</dd>
                    </div>
                    @endif
                    @if ($area)
                    <div data-ledger-row="area" class="py-4 border-t" style="{{ $hairline }}">
                        <dt class="text-xs font-semibold uppercase tracking-[0.18em] opacity-60 mb-1">Area covered</dt>
                        <dd class="font-medium">{{ $area }}</dd>
                    </div>
                    @endif
                    @if ($hours !== [])
                    <div data-ledger-row="hours" class="py-4 border-t" style="{{ $hairline }}">
                        <dt class="text-xs font-semibold uppercase tracking-[0.18em] opacity-60 mb-1">Hours</dt>
                        <dd class="font-medium space-y-0.5">@foreach ($hours as $row)<div class="flex justify-between gap-6"><span>{{ $row['day'] }}</span><span>{{ $row['hours'] }}</span></div>@endforeach</dd>
                    </div>
                    @endif
                </dl>
@endif
@endif
