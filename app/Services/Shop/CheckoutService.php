<?php

namespace App\Services\Shop;

use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\RefundStatus;
use App\Exceptions\Shop\CheckoutException;
use App\Exceptions\Shop\InsufficientStockException;
use App\Models\Shop\Cart;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\Site;
use App\Services\Shop\Fulfilment\FulfilmentConfig;
use App\Services\Shop\Fulfilment\FulfilmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    // 35, not 30: Stripe rejects a checkout session whose expires_at is less than 30
    // minutes out, and the session is now pinned to this deadline (see StripeService).
    // At exactly 30 the round-trip latency could put the value under Stripe's floor.
    public const PENDING_TTL_MINUTES = 35;

    /**
     * Grace between the customer's payment cutoff and our right to cancel.
     *
     * These are TWO different deadlines and conflating them loses money. expires_at is
     * the Stripe payment cutoff — after it, the customer can no longer pay. But Stripe
     * settlement and webhook delivery are asynchronous, so a payment completed a second
     * before the cutoff can be delivered well after it. Reaping on expires_at itself
     * cancelled such orders and released their stock before the webhook landed, and the
     * webhook then treated the cancelled order as an idempotent no-op: charged, no order,
     * silent 200. The reaper must wait out the delivery window.
     */
    public const REAP_GRACE_MINUTES = 30;

    public function __construct(
        protected TaxService $tax,
        protected ShippingService $shipping,
        protected OrderNumberService $numbers,
        protected StockService $stock,
        protected FulfilmentService $fulfilment,
        protected PersonalisationImageStore $images,
    ) {}

    /**
     * VAT-inclusive totals as {@see start()} will persist them.
     * Display-only — does not create an order or reserve stock.
     *
     * The GB default is for unit tests that call quote() without a site;
     * request paths must pass {@see \App\Models\Site::shopCountryCode()}.
     *
     * @return array{subtotal_cents: int, shipping_cents: int, shipping_label: string, vat_cents: int, total_cents: int, tax_rate_exists: bool, shipping_visible: bool, collect_address: ?string, fulfilment_method: ?string, fulfilment_zone_name: ?string, delivery_pending: bool, minimum_order_message: ?string}
     */
    public function quote(Cart $cart, string $countryCode = 'GB', ?string $fulfilmentMethod = null, ?string $postcode = null): array
    {
        $cart->loadMissing('items.variant.product.taxClass');

        $lines = [];
        foreach ($cart->items as $item) {
            $lines[] = [
                'unit_price_cents' => $item->unit_price_cents,
                'qty' => $item->qty,
                'tax_class_code' => $item->variant->product->taxClass?->code,
            ];
        }

        $taxed = $this->tax->calculateLines($lines, $countryCode);
        $subtotal = (int) array_sum(array_column($taxed, 'line_total_cents'));
        $tax = (int) array_sum(array_column($taxed, 'tax_amount_cents'));
        $resolved = $this->resolveShipping($cart, $subtotal, $fulfilmentMethod, $postcode ?? '', strict: false);
        $shippingTax = $this->tax->shippingTaxForCountry($countryCode, $resolved['cost_cents']);

        return [
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $resolved['cost_cents'],
            'shipping_label' => $resolved['method_label'],
            'vat_cents' => $tax + $shippingTax,
            'total_cents' => $subtotal + $resolved['cost_cents'],
            'tax_rate_exists' => $this->tax->hasRateForCountry($countryCode),
            'shipping_visible' => $resolved['shipping_visible'],
            'collect_address' => $resolved['collect_address'],
            'fulfilment_method' => $resolved['fulfilment_method'],
            'fulfilment_zone_name' => $resolved['zone_name'],
            'delivery_pending' => (bool) ($resolved['delivery_pending'] ?? false),
            'minimum_order_message' => $resolved['minimum_order_message'] ?? null,
        ];
    }

    public function start(Cart $cart, array $address): Order
    {
        $cart->loadMissing('site', 'items.variant.product');

        if ($cart->items->isEmpty()) {
            throw new CheckoutException('Cart is empty');
        }

        try {
            return DB::transaction(function () use ($cart, $address) {
                // Direct-attach only: a signed-in cart already has customer_id from
                // the session. Guest orders stay unlinked; the magic-link claim
                // attaches them later. Never look up a customer from the posted email.
                $customerId = $cart->customer_id;

                $lines = [];
                foreach ($cart->items as $item) {
                    $product = $item->variant->product;
                    $taxClassCode = $product->taxClass?->code;
                    $lines[] = [
                        'cart_item' => $item,
                        'variant' => $item->variant,
                        'product' => $product,
                        'unit_price_cents' => $item->unit_price_cents,
                        'qty' => $item->qty,
                        'tax_class_code' => $taxClassCode,
                    ];
                }

                $taxed = $this->tax->calculateLines(array_map(
                    fn ($l) => ['unit_price_cents' => $l['unit_price_cents'], 'qty' => $l['qty'], 'tax_class_code' => $l['tax_class_code']],
                    $lines
                ), $address['country_code']);

                $subtotal = array_sum(array_column($taxed, 'line_total_cents'));
                $tax = array_sum(array_column($taxed, 'tax_amount_cents'));

                $resolved = $this->resolveShipping(
                    $cart,
                    $subtotal,
                    $address['fulfilment_method'] ?? null,
                    $address['postcode'] ?? '',
                    strict: true,
                );
                $shippingTax = $this->tax->shippingTaxForCountry($address['country_code'], $resolved['cost_cents']);

                $total = $subtotal + $resolved['cost_cents'];

                $order = Order::create([
                    'site_id' => $cart->site_id,
                    'number' => $this->numbers->next($cart->site_id),
                    'customer_id' => $customerId,
                    'email' => $address['email'],
                    'name' => $address['name'],
                    'phone' => $address['phone'] ?? null,
                    'status' => OrderStatus::Pending->value,
                    'refund_status' => RefundStatus::None->value,
                    'refund_amount_cents' => 0,
                    'subtotal_cents' => $subtotal,
                    'shipping_cents' => $resolved['cost_cents'],
                    'tax_cents' => $tax,
                    'shipping_tax_cents' => $shippingTax,
                    'total_cents' => $total,
                    'tax_country_code' => $address['country_code'],
                    'shipping_address_json' => $address,
                    'shipping_method_label' => $resolved['method_label'],
                    'fulfilment_method' => $resolved['fulfilment_method'],
                    'fulfilment_zone_name' => $resolved['zone_name'] === null
                        ? null
                        : Str::limit((string) $resolved['zone_name'], FulfilmentConfig::NAME_MAX_LENGTH, ''),
                    'fulfilment_fee_cents' => $resolved['fulfilment_method'] !== null ? $resolved['fee_cents'] : null,
                    'fulfilment_postcode' => $resolved['postcode'],
                    'placed_at' => now(),
                    'expires_at' => now()->addMinutes(self::PENDING_TTL_MINUTES),
                ]);

                foreach ($lines as $i => $line) {
                    $t = $taxed[$i];
                    $personalisation = $line['cart_item']->personalisation;
                    if (is_array($personalisation)) {
                        $personalisation = $this->images->copyToOwner(
                            $personalisation,
                            $cart->site,
                            $this->images->ownerPrefix('order', (int) $order->id),
                        );
                    }

                    OrderItem::create([
                        'order_id' => $order->id,
                        'variant_id' => $line['variant']->id,
                        'product_id' => $line['product']->id,
                        'product_name_snapshot' => $line['product']->name,
                        'variant_label_snapshot' => $line['variant']->label,
                        'sku_snapshot' => $line['variant']->sku,
                        'qty' => $line['qty'],
                        'unit_price_cents' => $line['unit_price_cents'],
                        'tax_class_code' => $t['tax_class_code'],
                        'tax_rate_percent' => $t['tax_rate_percent'],
                        'tax_amount_cents' => $t['tax_amount_cents'],
                        'line_total_cents' => $t['line_total_cents'],
                        'personalisation' => is_array($personalisation) ? $personalisation : null,
                    ]);

                    // A stale cart (abandoned past the reservation TTL, its stock since taken
                    // by someone else) must not be allowed to check out on the dead
                    // reservation — attaching it would resurrect it and oversell. Re-reserve
                    // against current availability instead, and refuse if the stock has gone.
                    $attached = $line['cart_item']->reservation_id
                        && $this->stock->attachToOrder($line['cart_item']->reservation_id, $order->id);

                    if (! $attached) {
                        $fresh = $this->stock->reserve(
                            $line['cart_item']->variant_id,
                            $line['cart_item']->qty,
                            $cart->id,
                        );
                        $this->stock->attachToOrder($fresh->id, $order->id);
                        $line['cart_item']->update(['reservation_id' => $fresh->id]);
                    }
                }

                return $order;
            });
        } catch (InsufficientStockException $exception) {
            throw new CheckoutException(
                'One or more cart items are no longer available in the requested quantity.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{cost_cents: int, method_label: string, zone_name: ?string, fee_cents: int, shipping_visible: bool, collect_address: ?string, fulfilment_method: ?string, postcode: ?string, delivery_pending?: bool}
     */
    private function resolveShipping(Cart $cart, int $subtotalCents, ?string $method, string $postcode, bool $strict = false): array
    {
        $fallback = $this->shipping->calculate($cart->site_id, $subtotalCents, $cart->items);
        $site = Site::query()->find($cart->site_id);
        $config = $site ? $this->fulfilment->config($site) : null;

        $t19 = [
            'cost_cents' => $fallback['cost_cents'],
            'method_label' => $fallback['method_label'],
            'zone_name' => null,
            'fee_cents' => $fallback['cost_cents'],
            'shipping_visible' => true,
            'collect_address' => null,
            'fulfilment_method' => null,
            'postcode' => null,
        ];

        if ($config === null) {
            return $t19;
        }

        if ($method === null || $method === '') {
            if ($strict) {
                throw ValidationException::withMessages([
                    'fulfilment_method' => 'Choose a delivery option.',
                ]);
            }

            if ($config->methodEnabled('shipping')) {
                return $t19;
            }

            return [
                'cost_cents' => 0,
                'method_label' => '',
                'zone_name' => null,
                'fee_cents' => 0,
                'shipping_visible' => true,
                'collect_address' => null,
                'fulfilment_method' => null,
                'postcode' => null,
                'delivery_pending' => true,
            ];
        }

        $resolved = $this->fulfilment->quoteMethod(
            $site,
            $method,
            $postcode,
            $subtotalCents,
            $fallback['cost_cents'],
            $fallback['method_label'],
            strict: $strict,
        );

        $normalised = $postcode !== '' ? $this->fulfilment->normaliser($site)->normalise($postcode) : '';

        return [
            ...$resolved,
            'fulfilment_method' => $method,
            'postcode' => $normalised !== '' ? $normalised : null,
        ];
    }
}
