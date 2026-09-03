<?php

namespace App\Http\Controllers\Shop;

use App\Mail\SiteEnquiryReceived;
use App\Models\Shop\Cart;
use App\Models\Shop\Customer;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Services\Shop\CartService;
use App\Services\Shop\Fulfilment\FulfilmentService;
use App\Services\Shop\PersonalisationImageStore;
use App\Support\Shop\QuoteFormFields;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuoteController
{
    private const SESSION_ENQUIRY_KEY = 'shop.quote_enquiry_id';

    public function __construct(
        protected CartService $carts,
        protected FulfilmentService $fulfilment,
        protected PersonalisationImageStore $images,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $site = $this->quoteSite($request);
        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        $cart->load('items.variant.product.images', 'items.variant.product.variants');

        if ($cart->items->isEmpty()) {
            return redirect($request->getSchemeAndHttpHost().'/shop/cart')
                ->with('status', 'Your list is empty.');
        }

        $customer = $this->sessionCustomer($site);
        $config = $this->fulfilment->config($site);
        $enabledMethods = $config?->enabledMethods() ?? [];
        $session = $this->fulfilment->sessionFor($site, $request);
        $selected = old('fulfilment_method', $this->fulfilment->defaultMethod($site, $request));
        if (! is_string($selected) || ! in_array($selected, $enabledMethods, true)) {
            $selected = $enabledMethods[0] ?? null;
        }

        return view('shop.quote', [
            'site' => $site,
            'cart' => $cart,
            'customer' => $customer,
            'quoteToken' => $this->quoteToken($request, $cart),
            'honeypotField' => $site->enquiryHoneypotFieldName(),
            'prefillName' => $customer?->name ?? '',
            'prefillEmail' => $customer?->email ?? '',
            'prefillPhone' => $customer?->addresses()
                ->where('is_default_shipping', true)
                ->value('phone') ?? '',
            'fulfilmentActive' => $config !== null,
            'fulfilmentMethods' => $enabledMethods,
            'fulfilmentLabels' => $config === null ? [] : collect($enabledMethods)
                ->mapWithKeys(fn (string $method): array => [$method => $config->label($method)])
                ->all(),
            'selectedFulfilmentMethod' => $selected,
            'prefillPostcode' => old('fulfilment_postcode', $session['display'] ?? ''),
            'quoteExtraFields' => QuoteFormFields::extra(),
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $site = $this->quoteSite($request);

        $honeypotField = $site->enquiryHoneypotFieldName();
        $config = $this->fulfilment->config($site);
        $enabledMethods = $config?->enabledMethods() ?? [];
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            $honeypotField => ['nullable', 'string', 'max:255'],
            'quote_token' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
        $rules = array_merge($rules, QuoteFormFields::rules());
        if ($config !== null) {
            $rules['fulfilment_method'] = ['nullable', 'string', Rule::in($enabledMethods)];
            $rules['fulfilment_postcode'] = ['nullable', 'string', 'max:16'];
        }
        $validated = $request->validate($rules);

        if (($validated[$honeypotField] ?? '') !== '') {
            return redirect($request->getSchemeAndHttpHost().'/shop/quote/sent');
        }

        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        $token = $validated['quote_token'] ?? '';
        if ($token === '') {
            $token = 'cart:'.$cart->id;
        }

        $lock = Cache::lock('shop-quote-submit:'.$cart->id, 10);

        try {
            return $lock->block(5, function () use ($request, $site, $cart, $validated, $token): RedirectResponse {
                $doneKey = 'shop-quote-done:'.$cart->id.':'.$token;
                $existingId = Cache::get($doneKey);
                if (is_numeric($existingId)) {
                    $request->session()->put(self::SESSION_ENQUIRY_KEY, (int) $existingId);

                    return redirect($request->getSchemeAndHttpHost().'/shop/quote/sent');
                }

                $enquiry = DB::transaction(function () use ($request, $site, $cart, $validated) {
                    $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
                    if ($locked === null) {
                        return null;
                    }

                    $locked->load('items.variant.product.images', 'items.variant.product.variants');

                    if ($locked->items->isEmpty()) {
                        return null;
                    }

                    $customer = $this->sessionCustomer($site);
                    $currency = $site->shop_currency ?? 'GBP';
                    $lines = $locked->items->map(function ($item) use ($currency): array {
                        $product = $item->variant->product;

                        return [
                            'product_id' => $product->id,
                            'product_slug' => $product->slug,
                            'name' => $product->name,
                            'variant_id' => $item->variant_id,
                            'variant_label' => $item->variant->shopperFacingLabel(),
                            'qty' => (int) $item->qty,
                            'unit_price_cents' => (int) $item->unit_price_cents,
                            'currency' => $currency,
                            'personalisation' => is_array($item->personalisation) ? $item->personalisation : null,
                        ];
                    })->values()->all();

                    $payload = [
                        'kind' => 'quote',
                        'phone' => $validated['phone'] ?? '',
                        'needed_by' => $validated['needed_by'] ?? '',
                        'message' => $validated['message'] ?? '',
                        'lines' => $lines,
                    ];
                    $fieldLabels = [
                        'kind' => 'Kind',
                        'phone' => 'Phone',
                        'needed_by' => 'When do you need it?',
                        'message' => 'Message',
                        'lines' => 'Items',
                    ];
                    foreach (QuoteFormFields::extra() as $field) {
                        $name = $field['name'];
                        $value = $validated[$name] ?? '';
                        $payload[$name] = is_scalar($value) ? (string) $value : '';
                        $fieldLabels[$name] = $field['label'];
                    }
                    $config = $this->fulfilment->config($site);
                    if ($config !== null) {
                        $method = $validated['fulfilment_method'] ?? '';
                        $postcode = $validated['fulfilment_postcode'] ?? '';
                        $normalised = $postcode !== ''
                            ? $this->fulfilment->normaliser($site)->normalise((string) $postcode)
                            : '';
                        $zone = null;
                        if ($method === 'delivery' && $normalised !== '') {
                            $zone = app(\App\Services\Shop\Fulfilment\ZoneMatcher::class)
                                ->match($normalised, $config->zones(), $this->fulfilment->normaliser($site));
                        }
                        $payload['fulfilment'] = [
                            'method' => $method !== '' ? $method : null,
                            'label' => $method !== '' ? $config->label($method) : null,
                            'postcode' => $normalised !== '' ? $normalised : null,
                            'zone_name' => $zone['name'] ?? null,
                        ];
                        $fieldLabels['fulfilment'] = 'Fulfilment';
                    }

                    $enquiry = SiteEnquiry::create([
                        'site_id' => $site->id,
                        'customer_id' => $customer?->id,
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'payload' => $payload,
                        'field_labels' => $fieldLabels,
                        'page_type' => null,
                        'ip_hash' => hash('sha256', (string) $request->ip()),
                    ]);

                    $owner = $this->images->ownerPrefix('enquiry', (int) $enquiry->id);
                    foreach ($lines as $i => $line) {
                        if (is_array($line['personalisation'] ?? null)) {
                            $lines[$i]['personalisation'] = $this->images->copyToOwner($line['personalisation'], $site, $owner);
                        }
                    }
                    $payload = $enquiry->payload;
                    $payload['lines'] = $lines;
                    $enquiry->update(['payload' => $payload]);
                    $enquiry->load('site');

                    $this->carts->clear($locked);

                    DB::afterCommit(function () use ($site, $enquiry): void {
                        if ($site->enquiry_notification_email) {
                            Mail::to($site->enquiry_notification_email)->send(new SiteEnquiryReceived($enquiry));
                        }
                    });

                    return $enquiry;
                });

                if ($enquiry === null) {
                    return redirect($request->getSchemeAndHttpHost().'/shop/cart')
                        ->with('status', 'Your list is empty.');
                }

                Cache::put($doneKey, $enquiry->id, now()->addHour());
                $request->session()->forget('shop.quote_token.'.$cart->id);
                $request->session()->put(self::SESSION_ENQUIRY_KEY, $enquiry->id);

                return redirect($request->getSchemeAndHttpHost().'/shop/quote/sent');
            });
        } catch (LockTimeoutException) {
            return redirect('/shop/quote')->with('status', 'Your request is still being processed — please try again in a moment.');
        }
    }

    public function sent(Request $request): View
    {
        $site = $this->quoteSite($request);
        $enquiryId = $request->session()->get(self::SESSION_ENQUIRY_KEY);
        $enquiry = $enquiryId
            ? SiteEnquiry::query()->where('site_id', $site->id)->find($enquiryId)
            : null;

        return view('shop.quote-sent', [
            'site' => $site,
            'enquiry' => $enquiry,
        ]);
    }

    private function quoteSite(Request $request): Site
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site instanceof Site && $site->shopMode() === 'quote', 404);

        return $site;
    }

    private function sessionId(Request $request): string
    {
        return (string) ($request->cookie(CartController::COOKIE_NAME) ?: '');
    }

    private function quoteToken(Request $request, Cart $cart): string
    {
        $sessionKey = 'shop.quote_token.'.$cart->id;
        $token = $request->session()->get($sessionKey);
        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $request->session()->put($sessionKey, $token);
        }

        return $token;
    }

    private function sessionCustomer(Site $site): ?Customer
    {
        $customer = auth('customer')->user();

        if (! $customer || (int) $customer->site_id !== (int) $site->id) {
            return null;
        }

        return $customer;
    }
}
