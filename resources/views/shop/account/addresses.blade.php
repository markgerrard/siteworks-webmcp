@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $field = 'width: 100%; min-height: 44px; padding: 0.5rem 0.75rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);';
    $button = 'min-height: 44px; min-width: 44px; padding: 0.5rem 1rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);';
    $quiet = 'min-height: 44px; min-width: 44px; padding: 0.5rem 1rem; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: transparent; outline-color: var(--color-accent);';
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => 'Account', 'href' => route('shop.account')],
            ['label' => 'Addresses'],
        ]" />

        <h1 class="text-2xl font-bold mt-4 mb-6">Addresses</h1>

        @if ($addresses->isEmpty())
            <p class="mb-8" style="color: var(--color-text-muted)">You have no saved addresses yet.</p>
        @else
            <ul class="space-y-6 mb-10 max-w-xl">
                @foreach ($addresses as $address)
                    <li class="p-4" style="border: 1px solid var(--color-border); border-radius: var(--radius-card);">
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <div>
                                <div class="font-semibold">{{ $address->label ?: $address->name }}</div>
                                <p class="text-sm mt-1" style="color: var(--color-text-muted)">
                                    {{ $address->line1 }}
                                    @if ($address->line2), {{ $address->line2 }}@endif
                                    <br>
                                    {{ $address->city }}@if ($address->region), {{ $address->region }}@endif
                                    {{ $address->postcode }}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2 text-sm">
                                    @if ($address->is_default_shipping)
                                        <span style="background-color: var(--color-surface-alt); color: var(--color-text-on-alt); border-radius: var(--radius-button); padding: 0.15em 0.65em;">Default shipping</span>
                                    @endif
                                    @if ($address->is_default_billing)
                                        <span style="background-color: var(--color-surface-alt); color: var(--color-text-on-alt); border-radius: var(--radius-button); padding: 0.15em 0.65em;">Default billing</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @unless ($address->is_default_shipping)
                                    <form method="POST" action="/shop/account/addresses/{{ $address->id }}/default/shipping">
                                        @csrf
                                        <button type="submit" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $quiet }}">Set default shipping</button>
                                    </form>
                                @endunless
                                @unless ($address->is_default_billing)
                                    <form method="POST" action="/shop/account/addresses/{{ $address->id }}/default/billing">
                                        @csrf
                                        <button type="submit" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $quiet }}">Set default billing</button>
                                    </form>
                                @endunless
                                <form method="POST" action="/shop/account/addresses/{{ $address->id }}/delete">
                                    @csrf
                                    <button type="submit" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $quiet }}">Delete</button>
                                </form>
                            </div>
                        </div>

                        <form method="POST" action="/shop/account/addresses/{{ $address->id }}" class="space-y-3">
                            @csrf
                            <label class="block">
                                <span class="text-sm font-medium">Label</span>
                                <input name="label" value="{{ old('label', $address->label) }}" maxlength="40" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Full name</span>
                                <input name="name" value="{{ old('name', $address->name) }}" required class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Phone</span>
                                <input name="phone" value="{{ old('phone', $address->phone) }}" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Address line 1</span>
                                <input name="line1" value="{{ old('line1', $address->line1) }}" required class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Address line 2</span>
                                <input name="line2" value="{{ old('line2', $address->line2) }}" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">City</span>
                                <input name="city" value="{{ old('city', $address->city) }}" required class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Region</span>
                                <input name="region" value="{{ old('region', $address->region) }}" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Postcode</span>
                                <input name="postcode" value="{{ old('postcode', $address->postcode) }}" required maxlength="16" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium">Country</span>
                                <input name="country_code" value="{{ old('country_code', $address->country_code) }}" required maxlength="2" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                            </label>
                            <button type="submit" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $button }}">Save changes</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <section class="max-w-xl">
            <h2 class="font-semibold mb-3">Add an address</h2>
            <form method="POST" action="/shop/account/addresses" class="space-y-3">
                @csrf
                <label class="block">
                    <span class="text-sm font-medium">Label</span>
                    <input name="label" value="{{ old('label') }}" maxlength="40" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Full name</span>
                    <input name="name" value="{{ old('name', $customer->name) }}" required class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Phone</span>
                    <input name="phone" value="{{ old('phone') }}" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Address line 1</span>
                    <input name="line1" value="{{ old('line1') }}" required class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Address line 2</span>
                    <input name="line2" value="{{ old('line2') }}" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">City</span>
                    <input name="city" value="{{ old('city') }}" required class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Region</span>
                    <input name="region" value="{{ old('region') }}" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Postcode</span>
                    <input name="postcode" value="{{ old('postcode') }}" required maxlength="16" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Country</span>
                    <input name="country_code" value="{{ old('country_code', 'GB') }}" required maxlength="2" class="mt-1 w-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $field }}">
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="is_default_shipping" value="1" @checked(old('is_default_shipping'))>
                    <span>Default shipping</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="is_default_billing" value="1" @checked(old('is_default_billing'))>
                    <span>Default billing</span>
                </label>
                @if ($errors->any())
                    <ul class="text-sm" role="alert" style="color: var(--color-accent-text)">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
                <button type="submit" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="{{ $button }}">Save address</button>
            </form>
        </section>
    </div>
</x-shop.layout>
