@php
    $homeHref = app(\App\Services\Site\PageRenderer::class)->layoutContext($site)['homeHref'];
    $productName = $product['product_detail']['name'] ?? $product['product_card']['name'] ?? $product['slug'];
@endphp
<x-shop.layout :site="$site">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        <x-shop.breadcrumbs :trail="[
            ['label' => 'Home', 'href' => $homeHref],
            ['label' => 'Shop', 'href' => url('/shop')],
            ['label' => $productName, 'href' => \App\Support\Shop\ShopUrls::productAbsolute($product['slug'])],
            ['label' => 'Enquire'],
        ]" />

        <h1 class="text-2xl font-bold mb-2">Enquire about this cake</h1>
        <p class="mb-6" style="color: var(--color-text-muted)">{{ $productName }}</p>

        <div
            class="w-full max-w-xl"
            x-data="{ submitted: false, sending: false, error: '',
                      async submit(form) {
                          this.sending = true; this.error = '';
                          try {
                              const data = Object.fromEntries(new FormData(form).entries());
                              const res = await fetch('/enquiries', {
                                  method: 'POST',
                                  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                                  body: JSON.stringify(data),
                              });
                              if (!res.ok) throw new Error('bad response');
                              this.submitted = true;
                          } catch (e) {
                              this.error = 'Sorry, something went wrong — please try again or call us.';
                          } finally { this.sending = false; }
                      } }"
        >
            <div x-show="submitted" x-cloak class="py-8">
                <h2 class="text-xl font-bold mb-2">Thanks — we'll be in touch.</h2>
                <p style="color: var(--color-text-muted)">We've received your message and will get back to you shortly.</p>
            </div>

            <form x-show="!submitted" @submit.prevent="submit($event.target)" class="space-y-4">
                <input type="hidden" name="page_type" value="contact">
                <input type="hidden" name="product" value="{{ $productName }}">
                <input type="text" name="website" tabindex="-1" autocomplete="off"
                       style="position: absolute; left: -9999px;" aria-hidden="true">

                <label class="block">
                    <span class="text-sm font-medium">Name <span aria-hidden="true">*</span></span>
                    <input
                        type="text"
                        name="name"
                        required
                        autocomplete="name"
                        placeholder="Your name"
                        class="mt-1 w-full max-w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);"
                    >
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Email <span aria-hidden="true">*</span></span>
                    <input
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        inputmode="email"
                        placeholder="your@email.com"
                        class="mt-1 w-full max-w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);"
                    >
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Message <span aria-hidden="true">*</span></span>
                    <textarea
                        name="message"
                        rows="5"
                        required
                        class="mt-1 w-full max-w-full p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        style="min-height: 44px; color: var(--color-text); border: 1px solid var(--color-border); border-radius: var(--radius-button); background-color: var(--color-surface); outline-color: var(--color-accent);"
                    >{{ $message }}</textarea>
                </label>

                <p role="alert" x-show="error" x-text="error" x-cloak class="text-sm"></p>

                <button
                    type="submit"
                    x-bind:disabled="sending"
                    class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0.75rem 1.5rem; background-color: var(--color-primary); color: var(--color-text-on-primary); border-radius: var(--radius-button); outline-color: var(--color-accent);"
                >
                    <span x-show="!sending">Send enquiry</span>
                    <span x-show="sending" x-cloak>Sending…</span>
                </button>
            </form>
        </div>
    </div>
</x-shop.layout>
