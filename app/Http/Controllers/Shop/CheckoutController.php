<?php

namespace App\Http\Controllers\Shop;

use App\Models\Shop\Customer;
use App\Models\Shop\CustomerAddress;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\CustomerAddressService;
use App\Services\Shop\Fulfilment\FulfilmentService;
use App\Services\Shop\StripeService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController
{
    private const LAST_ORDER_SESSION_KEY = 'shop.last_order_id';

    public function __construct(
        protected CartService $carts,
        protected CheckoutService $checkout,
        protected StripeService $stripe,
        protected CustomerAddressService $addresses,
        protected FulfilmentService $fulfilment,
    ) {}

    public function show(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $cart = $this->carts->getOrCreate($site->id, $request->cookie(CartController::COOKIE_NAME) ?: '');
        $cart->load([
            'items.variant.product.taxClass',
            'items.variant.product.images',
            'items.variant.product.variants',
        ]);
        abort_if($cart->items->isEmpty(), 302, '', ['Location' => '/shop/cart']);

        $customer = $this->sessionCustomer($site);
        if ($customer) {
            $cart->update(['customer_id' => $customer->id]);
        }

        $prefill = $this->prefillFor($customer, $site);
        $config = $this->fulfilment->config($site);
        $enabledMethods = $config?->enabledMethods() ?? [];
        $session = $this->fulfilment->sessionFor($site, $request);
        $addressPostcode = old('postcode', $request->query('postcode', $prefill['values']['postcode'] ?? ''));
        $addressPostcode = is_string($addressPostcode) ? trim($addressPostcode) : '';
        $widgetDisplay = is_string($session['display'] ?? null) ? trim($session['display']) : '';
        $widgetPostcode = $widgetDisplay !== ''
            ? $widgetDisplay
            : (is_string($session['postcode'] ?? null) ? $session['postcode'] : '');
        $pricingFromWidget = $addressPostcode === '' && $widgetPostcode !== '';
        $postcode = $addressPostcode !== '' ? $addressPostcode : $widgetPostcode;
        $deliveryZoneMatched = $this->fulfilment->matchedZone($site, $postcode) !== null;
        $selectedMethod = $this->selectedFulfilmentMethod($request, $site, $enabledMethods, $deliveryZoneMatched);

        $quote = $this->checkout->quote(
            $cart,
            $site->shopCountryCode(),
            $selectedMethod,
            $postcode !== '' ? $postcode : null,
        );
        $deliveryPending = $selectedMethod === 'delivery' && ! $deliveryZoneMatched;

        return view('shop.checkout', [
            'site' => $site,
            'cart' => $cart,
            'subtotal_cents' => $quote['subtotal_cents'],
            'shipping_cents' => $quote['shipping_cents'],
            'shipping_label' => $quote['shipping_label'],
            'vat_cents' => $quote['vat_cents'],
            'total_cents' => $quote['total_cents'],
            'tax_rate_exists' => $quote['tax_rate_exists'],
            'shipping_visible' => $quote['shipping_visible'] && ! $deliveryPending,
            'collect_address' => $quote['collect_address'],
            'signedIn' => (bool) $customer,
            'hasDefaultShipping' => $prefill['has_default_shipping'],
            'prefill' => $prefill['values'],
            'fulfilmentActive' => $config !== null,
            'fulfilmentMethods' => $enabledMethods,
            'fulfilmentLabels' => $config === null ? [] : collect($enabledMethods)
                ->mapWithKeys(fn (string $method): array => [$method => $config->label($method)])
                ->all(),
            'selectedFulfilmentMethod' => $selectedMethod,
            'checkoutPostcode' => $addressPostcode,
            'addressPostcode' => $addressPostcode,
            'pricing_from_widget' => $pricingFromWidget,
            'widget_postcode_display' => $widgetPostcode,
            'deliveryZoneMatched' => $deliveryZoneMatched,
            'delivery_pending' => $deliveryPending,
            'minimum_order_message' => $quote['minimum_order_message'] ?? null,
        ]);
    }

    public function start(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $config = $this->fulfilment->config($site);
        $enabledMethods = $config?->enabledMethods() ?? [];
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:64',
            'line1' => 'required|string|max:255',
            'line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'postcode' => 'required|string|max:16',
            'country_code' => 'required|string|size:2',
        ];
        if ($config !== null) {
            $rules['fulfilment_method'] = ['required', 'string', Rule::in($enabledMethods)];
        }
        $data = $request->validate($rules);
        $data['country_code'] = $site->shopCountryCode();
        unset($data['fee_cents'], $data['zone_name']);

        $cart = $this->carts->getOrCreate($site->id, $request->cookie(CartController::COOKIE_NAME) ?: '');
        $customer = $this->sessionCustomer($site);
        if ($customer) {
            $cart->update(['customer_id' => $customer->id]);
        }

        try {
            $order = $this->checkout->start($cart, $data);
        } catch (ValidationException $exception) {
            $postcodeErrors = $exception->errors()['postcode'] ?? [];
            $unmatched = collect($postcodeErrors)->contains(
                fn (string $message): bool => str_contains($message, 'not in our delivery area'),
            );
            if ($unmatched) {
                throw new HttpResponseException(
                    redirect()->back()
                        ->withInput()
                        ->withErrors($exception->errors())
                        ->setStatusCode(422),
                );
            }

            throw $exception;
        }

        if ($customer && $request->boolean('save_address')) {
            $this->upsertCheckoutAddress($customer, $data);
        }

        $session = $this->stripe->createCheckoutSession(
            orderId: $order->id,
            orderNumber: $order->number,
            totalCents: $order->total_cents,
            currency: strtolower($site->shop_currency ?? 'GBP'),
            customerEmail: $order->email,
            expiresAt: $order->expires_at,
            successUrl: $request->getSchemeAndHttpHost()."/shop/checkout/success?order={$order->id}",
            cancelUrl: $request->getSchemeAndHttpHost()."/shop/checkout/cancel?order={$order->id}",
            lineDescriptor: "Order {$order->number}",
        );

        $order->update(['stripe_checkout_session_id' => $session->id]);
        $request->session()->put(self::LAST_ORDER_SESSION_KEY, $order->id);
        $cart->update(['email' => $order->email, 'converted_order_id' => $order->id]);

        return redirect($session->url);
    }

    public function success(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        return view('shop.checkout-success', [
            'site' => $site,
            'order' => $this->orderForRequest($request, $site),
        ]);
    }

    public function cancel(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        return view('shop.checkout-cancel', [
            'site' => $site,
            'order' => $this->orderForRequest($request, $site),
        ]);
    }

    /**
     * The order the CURRENT visitor just placed, or null.
     *
     * Deliberately does NOT trust ?order= from the URL. Reading the id off the query
     * string and scoping it only to the site let anyone enumerate ids and read back
     * another shopper's order number — the exact identifier a refund-fraud or
     * support-desk approach wants, on a page that will accrue more order detail over
     * time. The id is written into the session when checkout starts, and the session
     * cookie is host-only (see ScopeSessionToStorefrontHost), so it survives the Stripe
     * round-trip back to this storefront and nowhere else.
     */
    private function orderForRequest(Request $request, Site $site): ?Order
    {
        $id = $request->session()->get(self::LAST_ORDER_SESSION_KEY);
        if (! is_numeric($id)) {
            return null;
        }

        return Order::query()
            ->where('site_id', $site->id)
            ->find((int) $id);
    }

    /**
     * @param  list<string>  $enabledMethods
     */
    private function selectedFulfilmentMethod(Request $request, Site $site, array $enabledMethods, bool $deliverySelectable): ?string
    {
        if ($enabledMethods === []) {
            return null;
        }

        $usable = array_values(array_filter(
            $enabledMethods,
            fn (string $method): bool => $method !== 'delivery' || $deliverySelectable,
        ));

        $posted = $request->query('fulfilment_method', old('fulfilment_method'));
        if (is_string($posted) && in_array($posted, $enabledMethods, true)) {
            if ($posted !== 'delivery' || $deliverySelectable) {
                return $posted;
            }
        }

        $default = $this->fulfilment->defaultMethod($site, $request);
        if ($default !== null && in_array($default, $usable, true)) {
            return $default;
        }

        return $usable[0] ?? $enabledMethods[0];
    }

    private function sessionCustomer(Site $site): ?Customer
    {
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            return null;
        }

        return (int) $customer->site_id === (int) $site->id ? $customer : null;
    }

    /**
     * @return array{has_default_shipping: bool, values: array<string, mixed>}
     */
    private function prefillFor(?Customer $customer, Site $site): array
    {
        if (! $customer) {
            return ['has_default_shipping' => false, 'values' => []];
        }

        $default = CustomerAddress::query()
            ->forCustomer($customer)
            ->where('is_default_shipping', true)
            ->first();

        if ($default) {
            return [
                'has_default_shipping' => true,
                'values' => [
                    'name' => $default->name,
                    'email' => $customer->email,
                    'phone' => $default->phone,
                    'line1' => $default->line1,
                    'line2' => $default->line2,
                    'city' => $default->city,
                    'postcode' => $default->postcode,
                    'country_code' => $site->shopCountryCode(),
                ],
            ];
        }

        $lastOrder = Order::query()
            ->where('site_id', $site->id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('placed_at')
            ->first();
        $fromOrder = is_array($lastOrder?->shipping_address_json) ? $lastOrder->shipping_address_json : [];

        return [
            'has_default_shipping' => false,
            'values' => [
                'name' => $fromOrder['name'] ?? $customer->name,
                'email' => $fromOrder['email'] ?? $customer->email,
                'phone' => $fromOrder['phone'] ?? null,
                'line1' => $fromOrder['line1'] ?? null,
                'line2' => $fromOrder['line2'] ?? null,
                'city' => $fromOrder['city'] ?? null,
                'postcode' => $fromOrder['postcode'] ?? null,
                'country_code' => $site->shopCountryCode(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertCheckoutAddress(Customer $customer, array $data): void
    {
        $payload = [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'line1' => $data['line1'],
            'line2' => $data['line2'] ?? null,
            'city' => $data['city'],
            'postcode' => $data['postcode'],
            'country_code' => $data['country_code'],
            'is_default_shipping' => true,
        ];

        $existing = CustomerAddress::query()
            ->forCustomer($customer)
            ->where('is_default_shipping', true)
            ->first();

        if ($existing) {
            $this->addresses->update($existing, $payload);
            $this->addresses->setDefault($existing, 'shipping');

            return;
        }

        $this->addresses->create($customer, $payload);
    }
}
