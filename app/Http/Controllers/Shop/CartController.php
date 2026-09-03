<?php

namespace App\Http\Controllers\Shop;

use App\Exceptions\Shop\InsufficientStockException;
use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Site;
use App\Services\Shop\CartDrawerPayload;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\CustomerInputDefinition;
use App\Services\Shop\Fulfilment\FulfilmentService;
use App\Services\Shop\LinePersonalisation;
use App\Services\Shop\PersonalisationImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController
{
    /** Per-request memo for sessionId(); see the note there. */
    private ?string $sessionId = null;

    public const COOKIE_NAME = 'shop_session';

    public function __construct(
        protected CartService $carts,
        protected CheckoutService $checkout,
        protected CartDrawerPayload $drawer,
        protected PersonalisationImageStore $images,
        protected FulfilmentService $fulfilment,
    ) {}

    public function show(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        $cart->load('items.variant.product.images', 'items.variant.product.taxClass', 'items.variant.product.variants');

        if ($this->wantsCartJson($request)) {
            return response()->json($this->drawer->for($site, $cart));
        }

        $quote = $cart->items->isEmpty()
            ? [
                'subtotal_cents' => 0,
                'shipping_cents' => 0,
                'shipping_label' => null,
                'vat_cents' => 0,
                'total_cents' => 0,
                'tax_rate_exists' => false,
                'shipping_visible' => true,
                'collect_address' => null,
                'fulfilment_method' => null,
                'delivery_pending' => false,
                'minimum_order_message' => null,
            ]
            : $this->checkout->quote(
                $cart,
                $site->shopCountryCode(),
                ...$this->cartFulfilmentQuoteArgs($site, $request),
            );

        $deliveryPending = (bool) ($quote['delivery_pending'] ?? false);
        $method = $quote['fulfilment_method'] ?? null;
        $shippingLabel = $quote['shipping_label'] ?? null;
        $showShippingAmount = ! $deliveryPending && $method !== 'collect';
        $shippingHeading = $deliveryPending
            ? 'Delivery calculated at checkout'
            : ($method === 'collect'
                ? (string) ($shippingLabel ?: 'Click & collect')
                : ('Shipping'.($shippingLabel ? ' — '.$shippingLabel : '')));

        return view('shop.cart', [
            'site' => $site,
            'cart' => $cart,
            'subtotal_cents' => $quote['subtotal_cents'],
            'shipping_cents' => $quote['shipping_cents'],
            'shipping_label' => $shippingLabel,
            'shipping_heading' => $shippingHeading,
            'show_shipping_amount' => $showShippingAmount,
            'vat_cents' => $quote['vat_cents'],
            'total_cents' => $quote['total_cents'],
            'tax_rate_exists' => $quote['tax_rate_exists'],
            'shipping_visible' => $quote['shipping_visible'] ?? true,
            'collect_address' => $quote['collect_address'] ?? null,
            'delivery_pending' => $deliveryPending,
            'minimum_order_message' => $quote['minimum_order_message'] ?? null,
        ]);
    }

    public function add(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);
        $json = $this->wantsCartJson($request);
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        $data = $request->validate([
            'product_slug' => 'required|string',
            'variant_id' => 'required|integer',
            'qty' => 'required|integer|min:1|max:99',
        ]);

        $product = Product::where('site_id', $site->id)
            ->where('slug', $data['product_slug'])
            ->firstOrFail();

        // Bind the variant to the posted product, which is itself bound to the resolved
        // site. Validating variant_id as `integer` and trusting it let a request to site
        // A carry site B's variant: B's stock was reserved and A's customer was charged
        // B's price. The product lookup above used to be a bare firstOrFail() whose
        // result was discarded, so it proved nothing about the variant.
        $variant = ProductVariant::where('id', (int) $data['variant_id'])
            ->where('product_id', $product->id)
            ->first();

        if (! $variant) {
            if ($json) {
                $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));

                return $this->jsonMutationError($site, $cart, 'unavailable', 'That option is not available.');
            }

            return redirect()->back()->withErrors(['variant' => 'That option is not available.']);
        }

        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        $personalisation = null;

        try {
            $personalisation = $this->capturePersonalisation($request, $site, $product, $cart);
            $this->carts->addItem($cart, $variant->id, (int) $data['qty'], $personalisation);
        } catch (ValidationException $e) {
            if ($json) {
                return $this->jsonMutationError($site, $cart, 'validation', collect($e->errors())->flatten()->first() ?: 'Check the required fields.');
            }

            throw $e;
        } catch (InsufficientStockException $e) {
            $this->deleteUploadedFiles($personalisation);
            if ($json) {
                return $this->jsonMutationError($site, $cart, 'insufficient_stock', 'Not enough stock available.');
            }

            return redirect()->back()->withErrors(['stock' => 'Not enough stock available.']);
        }

        $response = $this->afterMutation($request, $site, $cart, $json, $product);

        return $response->cookie(self::COOKIE_NAME, $this->sessionId($request), 60 * 24 * 30);
    }

    public function update(Request $request, int $itemId)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);
        $json = $this->wantsCartJson($request);
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        $data = $request->validate([
            'qty' => 'required|integer|min:1|max:99',
        ]);
        $qty = (int) $data['qty'];
        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        try {
            $this->carts->setQty($cart, $itemId, $qty);
        } catch (InsufficientStockException $e) {
            if ($json) {
                return $this->jsonMutationError($site, $cart, 'insufficient_stock', 'Not enough stock available.');
            }

            throw $e;
        }

        return $this->afterMutation($request, $site, $cart, $json);
    }

    public function remove(Request $request, int $itemId)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);
        $json = $this->wantsCartJson($request);
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        $this->carts->removeItem($cart, $itemId);

        return $this->afterMutation($request, $site, $cart, $json);
    }

    public function updatePersonalisation(Request $request, int $itemId)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);
        $json = $this->wantsCartJson($request);
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        $cart = $this->carts->getOrCreate($site->id, $this->sessionId($request));
        $item = CartItem::where('cart_id', $cart->id)->findOrFail($itemId);
        $product = $item->variant->product;
        $definitions = $this->definitionsForEdit($item);

        $previousPersonalisation = $item->personalisation;
        $personalisation = null;

        try {
            $personalisation = $this->capturePersonalisation($request, $site, $product, $cart, $definitions, $item);
            $this->carts->updatePersonalisation($cart, $itemId, $personalisation);
        } catch (ValidationException $e) {
            if ($json) {
                return $this->jsonMutationError($site, $cart, 'validation', collect($e->errors())->flatten()->first() ?: 'Check the required fields.');
            }

            throw $e;
        } catch (InsufficientStockException $e) {
            $this->deleteUploadedFiles($personalisation, $previousPersonalisation);
            if ($json) {
                return $this->jsonMutationError($site, $cart, 'insufficient_stock', 'Not enough stock available.');
            }

            throw $e;
        }

        return $this->afterMutation($request, $site, $cart, $json);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function cartFulfilmentQuoteArgs(Site $site, Request $request): array
    {
        $config = $this->fulfilment->config($site);
        if ($config === null) {
            return [null, null];
        }

        $session = $this->fulfilment->sessionFor($site, $request);
        $postcode = is_string($session['postcode'] ?? null) ? $session['postcode'] : null;
        $zone = is_array($session['zone'] ?? null) ? $session['zone'] : null;

        if ($zone !== null && $config->methodEnabled('delivery')) {
            return ['delivery', $postcode];
        }

        if ($session !== null && $config->methodEnabled('collect')) {
            return ['collect', $postcode];
        }

        if ($config->methodEnabled('shipping')) {
            return ['shipping', $postcode];
        }

        return [null, $postcode];
    }

    /**
     * The cart session id for THIS request.
     *
     * Memoised deliberately. This used to mint a fresh uuid on every call, and add()
     * calls it twice — once to create the cart row, once to set the cookie — so a
     * first-time shopper got two different ids and their first add-to-cart was
     * written under an id the browser never sends back. Every new shopper silently
     * lost their first item. Keep this memoised, or that returns.
     */
    private function sessionId(Request $request): string
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        return $this->sessionId = $request->cookie(self::COOKIE_NAME)
            ?: Str::uuid()->toString();
    }

    private function wantsCartJson(Request $request): bool
    {
        if ($request->wantsJson()) {
            return true;
        }

        return strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
    }

    private function afterMutation(Request $request, Site $site, Cart $cart, bool $json, ?Product $recentlyAdded = null): JsonResponse|RedirectResponse
    {
        if (! $json) {
            return redirect($request->getSchemeAndHttpHost().'/shop/cart');
        }

        $cart->unsetRelation('items');
        $cart->load('items.variant.product.images');

        return response()->json($this->drawer->for($site, $cart, $recentlyAdded));
    }

    /**
     * @param  'insufficient_stock'|'unavailable'|'validation'  $code
     */
    private function jsonMutationError(Site $site, Cart $cart, string $code, string $message): JsonResponse
    {
        $cart->unsetRelation('items');
        $cart->load('items.variant.product.images');

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'cart' => $this->drawer->for($site, $cart),
        ], 422);
    }

    /**
     * @param  list<array<string, mixed>>|null  $definitions
     * @return array<string, mixed>|null
     */
    private function capturePersonalisation(
        Request $request,
        Site $site,
        Product $product,
        Cart $cart,
        ?array $definitions = null,
        ?CartItem $item = null,
    ): ?array {
        $definitions ??= CustomerInputDefinition::normalize($product->customer_inputs ?? []);
        $posted = $request->input('personalisation', []);
        $postedFiles = $request->file('personalisation', []);
        $postedKeys = array_unique(array_merge(
            is_array($posted) ? array_keys($posted) : [],
            is_array($postedFiles) ? array_keys($postedFiles) : [],
        ));
        $allowed = array_column($definitions, 'slug');
        foreach ($postedKeys as $slug) {
            if (! in_array($slug, $allowed, true)) {
                throw ValidationException::withMessages([
                    "personalisation.{$slug}" => ['That field is not defined on this product.'],
                ]);
            }
        }
        if ($definitions === []) {
            return $item?->personalisation;
        }

        $owner = $this->images->ownerPrefix('cart', (int) $cart->id);
        $submitted = [];
        $storedFiles = [];

        try {
            foreach ($definitions as $definition) {
                $slug = $definition['slug'];
                if ($definition['kind'] === 'image') {
                    $uploads = $request->file('personalisation.'.$slug);
                    if ($uploads === null) {
                        $existing = $this->existingImageValue($item, $slug);
                        if ($existing !== null) {
                            $submitted[$slug] = $existing;
                        }

                        continue;
                    }
                    $uploads = is_array($uploads) ? $uploads : [$uploads];
                    $files = [];
                    foreach ($uploads as $upload) {
                        if ($upload === null) {
                            continue;
                        }
                        $stored = $this->images->store($site, $owner, $upload);
                        $storedFiles[] = $stored;
                        $files[] = $stored;
                    }
                    if ($files !== []) {
                        $submitted[$slug] = $files;
                    }

                    continue;
                }

                if ($request->exists('personalisation.'.$slug)) {
                    $submitted[$slug] = $request->input('personalisation.'.$slug);
                } elseif (is_array($item?->personalisation) && array_key_exists($slug, $item->personalisation)) {
                    $submitted[$slug] = $item->personalisation[$slug]['value'] ?? null;
                }
            }

            return LinePersonalisation::freeze($definitions, $submitted);
        } catch (\Throwable $e) {
            $this->images->delete($storedFiles);
            throw $e;
        }
    }

    /**
     * Frozen definitions on the line win so a later product edit cannot rewrite history.
     *
     * @return list<array<string, mixed>>
     */
    private function definitionsForEdit(CartItem $item): array
    {
        $frozen = $item->personalisation;
        if (is_array($frozen) && $frozen !== []) {
            return LinePersonalisation::definitionsFromFrozen($frozen);
        }

        $product = $item->variant?->product;

        return CustomerInputDefinition::normalize($product?->customer_inputs ?? []);
    }

    /**
     * @return list<array{path: string, name: string, bytes: int, mime: string}>|null
     */
    private function existingImageValue(?CartItem $item, string $slug): ?array
    {
        if ($item === null || ! is_array($item->personalisation)) {
            return null;
        }
        $entry = $item->personalisation[$slug] ?? null;
        if (! is_array($entry) || ($entry['kind'] ?? null) !== 'image' || ! is_array($entry['value'] ?? null)) {
            return null;
        }

        return $entry['value'];
    }

    /**
     * @param  array<string, mixed>|null  $personalisation
     * @param  array<string, mixed>|null  $previous
     */
    private function deleteUploadedFiles(?array $personalisation, ?array $previous = null): void
    {
        $previousPaths = array_column(LinePersonalisation::imageFiles($previous), 'path');
        $uploaded = array_values(array_filter(
            LinePersonalisation::imageFiles($personalisation),
            fn (array $file): bool => ! in_array($file['path'], $previousPaths, true),
        ));
        $this->images->delete($uploaded);
    }
}
