<?php

namespace App\Services\Shop;

use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\ProductVariant;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(
        protected StockService $stock,
        protected PersonalisationImageStore $images,
    ) {}

    public function getOrCreate(int $siteId, string $sessionCookieId): Cart
    {
        return Cart::firstOrCreate(
            ['site_id' => $siteId, 'session_cookie_id' => $sessionCookieId, 'converted_order_id' => null],
            ['last_active_at' => now()]
        );
    }

    public function itemCountForSession(int $siteId, ?string $sessionCookieId): int
    {
        if ($sessionCookieId === null || $sessionCookieId === '') {
            return 0;
        }

        $cart = Cart::query()
            ->where('site_id', $siteId)
            ->where('session_cookie_id', $sessionCookieId)
            ->whereNull('converted_order_id')
            ->first();

        return $cart ? (int) $cart->items()->sum('qty') : 0;
    }

    public function addItem(Cart $cart, int $variantId, int $qty, ?array $personalisation = null): CartItem
    {
        $hash = LinePersonalisation::hash($personalisation);

        return DB::transaction(function () use ($cart, $variantId, $qty, $personalisation, $hash) {
            $existing = CartItem::where('cart_id', $cart->id)
                ->where('variant_id', $variantId)
                ->where('personalisation_hash', $hash)
                ->lockForUpdate()
                ->first();

            $newQty = ($existing?->qty ?? 0) + $qty;

            if ($existing && $existing->reservation_id) {
                $this->stock->release($existing->reservation_id, 'cart_updated');
            }

            $reservationId = $this->reserveIfNeeded($cart, $variantId, $newQty);

            $price = ProductVariant::where('id', $variantId)->value('price_cents');

            if ($existing) {
                $existing->update([
                    'qty' => $newQty,
                    'reservation_id' => $reservationId,
                ]);
                $item = $existing;
            } else {
                $item = CartItem::create([
                    'cart_id' => $cart->id,
                    'variant_id' => $variantId,
                    'qty' => $newQty,
                    'unit_price_cents' => $price,
                    'reservation_id' => $reservationId,
                    'personalisation' => $personalisation,
                    'personalisation_hash' => $hash,
                ]);
            }

            $cart->update(['last_active_at' => now()]);

            return $item;
        });
    }

    public function setQty(Cart $cart, int $itemId, int $qty): void
    {
        DB::transaction(function () use ($cart, $itemId, $qty) {
            $item = CartItem::where('cart_id', $cart->id)->lockForUpdate()->findOrFail($itemId);

            if ($qty <= 0) {
                $this->removeItem($cart, $itemId);

                return;
            }

            if ($item->reservation_id) {
                $this->stock->release($item->reservation_id, 'cart_updated');
            }
            $reservationId = $this->reserveIfNeeded($cart, $item->variant_id, $qty);

            $item->update(['qty' => $qty, 'reservation_id' => $reservationId]);
            $cart->update(['last_active_at' => now()]);
        });
    }

    public function removeItem(Cart $cart, int $itemId): void
    {
        DB::transaction(function () use ($cart, $itemId) {
            $item = CartItem::where('cart_id', $cart->id)->findOrFail($itemId);
            if ($item->reservation_id) {
                $this->stock->release($item->reservation_id, 'cart_item_removed');
            }
            $files = LinePersonalisation::imageFiles($item->personalisation);
            $item->delete();
            $this->images->delete($files);
            $cart->update(['last_active_at' => now()]);
        });
    }

    public function subtotalCents(Cart $cart): int
    {
        return (int) $cart->items()->sum(DB::raw('qty * unit_price_cents'));
    }

    public function clear(Cart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            foreach ($cart->items()->lockForUpdate()->get() as $item) {
                if ($item->reservation_id) {
                    $this->stock->release($item->reservation_id, 'cart_cleared');
                }
                $this->images->delete(LinePersonalisation::imageFiles($item->personalisation));
                $item->delete();
            }
            $cart->update(['last_active_at' => now()]);
        });
    }

    public function updatePersonalisation(Cart $cart, int $itemId, ?array $personalisation): CartItem
    {
        $hash = LinePersonalisation::hash($personalisation);

        return DB::transaction(function () use ($cart, $itemId, $personalisation, $hash) {
            $item = CartItem::where('cart_id', $cart->id)->lockForUpdate()->findOrFail($itemId);
            $previousFiles = LinePersonalisation::imageFiles($item->personalisation);
            $nextFiles = LinePersonalisation::imageFiles($personalisation);
            $nextPaths = array_column($nextFiles, 'path');

            $duplicate = CartItem::where('cart_id', $cart->id)
                ->where('variant_id', $item->variant_id)
                ->where('personalisation_hash', $hash)
                ->where('id', '!=', $item->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                $mergedQty = $duplicate->qty + $item->qty;

                if ($duplicate->reservation_id) {
                    $this->stock->release($duplicate->reservation_id, 'cart_updated');
                }
                if ($item->reservation_id) {
                    $this->stock->release($item->reservation_id, 'cart_updated');
                }

                $reservationId = $this->reserveIfNeeded($cart, $duplicate->variant_id, $mergedQty);
                $duplicate->update([
                    'qty' => $mergedQty,
                    'reservation_id' => $reservationId,
                ]);
                $item->delete();
                $this->images->delete(array_values(array_filter(
                    $previousFiles,
                    fn (array $file): bool => ! in_array($file['path'], $nextPaths, true),
                )));
                $cart->update(['last_active_at' => now()]);

                return $duplicate->fresh();
            }

            $item->update([
                'personalisation' => $personalisation,
                'personalisation_hash' => $hash,
            ]);
            $this->images->delete(array_values(array_filter(
                $previousFiles,
                fn (array $file): bool => ! in_array($file['path'], $nextPaths, true),
            )));
            $cart->update(['last_active_at' => now()]);

            return $item->fresh();
        });
    }

    private function reserveIfNeeded(Cart $cart, int $variantId, int $qty): ?int
    {
        $cart->loadMissing('site');

        if (! $cart->site?->shopReservesStock()) {
            return null;
        }

        return $this->stock->reserve($variantId, $qty, $cart->id)->id;
    }
}
