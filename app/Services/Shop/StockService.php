<?php

namespace App\Services\Shop;

use App\Enums\Shop\InventoryReason;
use App\Exceptions\Shop\InsufficientStockException;
use App\Models\Shop\InventoryMovement;
use App\Models\Shop\StockReservation;
use App\Models\Shop\VariantStock;
use Illuminate\Support\Facades\DB;

class StockService
{
    public const RESERVATION_TTL_MINUTES = 30;

    /**
     * Bulk read: variant_id => on_hand count. Single query, no locks.
     *
     * Safe for read-model builds (snapshot). NOT for transactional decisions —
     * use `available()` inside a transaction for that.
     */
    public function onHandMap(array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }

        return VariantStock::whereIn('variant_id', $variantIds)
            ->pluck('on_hand', 'variant_id')
            ->toArray();
    }

    /**
     * Bulk read: variant_id => qty held by active reservations (cart TTL + unpaid orders).
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function reservedMap(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return StockReservation::query()
            ->active()
            ->whereIn('variant_id', $variantIds)
            ->groupBy('variant_id')
            ->selectRaw('variant_id, sum(qty) as qty')
            ->pluck('qty', 'variant_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();
    }

    /**
     * Create the zero-on_hand stock row a new variant needs. Idempotent.
     * Records no inventory_movements row — quantity writes stay out of scope.
     */
    public function initialiseVariant(int $variantId): void
    {
        if (VariantStock::query()->where('variant_id', $variantId)->exists()) {
            return;
        }

        VariantStock::query()->create([
            'variant_id' => $variantId,
            'on_hand' => 0,
            'updated_at' => now(),
        ]);
    }

    public function available(int $variantId, bool $lock = true): int
    {
        $query = VariantStock::where('variant_id', $variantId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $stock = $query->first();
        $onHand = $stock?->on_hand ?? 0;

        $reserved = StockReservation::active()
            ->forVariant($variantId)
            ->sum('qty');

        return $onHand - $reserved;
    }

    public function reserve(int $variantId, int $qty, int $cartId): StockReservation
    {
        return DB::transaction(function () use ($variantId, $qty, $cartId) {
            if ($this->available($variantId) < $qty) {
                throw new InsufficientStockException("Not enough stock for variant {$variantId}");
            }

            return StockReservation::create([
                'variant_id' => $variantId,
                'cart_id' => $cartId,
                'order_id' => null,
                'qty' => $qty,
                'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
            ]);
        });
    }

    /**
     * Bind a live cart reservation to an order.
     *
     * The expiry guard is load-bearing. `scopeActive` exempts any row carrying an
     * order_id (an order owns its own lifecycle), so stamping order_id on an EXPIRED
     * row flips it back into the active set — after another cart may already have taken
     * the freed stock. That is a real oversell: availability goes negative and two paid
     * orders share one unit. This path did not exist before expiry was made
     * effective, so the guard has to live here too.
     *
     * Returns false when the reservation is no longer attachable; the caller must
     * re-reserve or refuse the checkout rather than proceed on a dead reservation.
     */
    public function attachToOrder(int $reservationId, int $orderId): bool
    {
        $affected = StockReservation::where('id', $reservationId)
            ->whereNull('released_at')
            ->whereNull('committed_at')
            ->whereNull('order_id')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->update(['order_id' => $orderId]);

        return $affected === 1;
    }

    /**
     * Commit a reservation: decrement on_hand, stamp committed_at, log movement.
     *
     * Idempotent — if the reservation is already committed or has been released,
     * this is a silent no-op. That matters for retried/duplicated webhooks.
     */
    public function commit(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {
            $reservation = StockReservation::lockForUpdate()->find($reservationId);

            // Reservation gone, released, or already committed → no-op.
            if (! $reservation || $reservation->released_at || $reservation->committed_at) {
                return;
            }

            VariantStock::where('variant_id', $reservation->variant_id)
                ->decrement('on_hand', $reservation->qty);

            VariantStock::where('variant_id', $reservation->variant_id)
                ->update(['updated_at' => now()]);

            $reservation->update(['committed_at' => now()]);

            InventoryMovement::create([
                'variant_id' => $reservation->variant_id,
                'delta' => -$reservation->qty,
                'reason' => InventoryReason::Sale->value,
                'reference_type' => 'order',
                'reference_id' => $reservation->order_id,
                'created_at' => now(),
            ]);
        });
    }

    public function release(int $reservationId, string $reason): void
    {
        StockReservation::where('id', $reservationId)
            ->whereNull('released_at')
            ->whereNull('committed_at')
            ->update(['released_at' => now()]);
    }

    public function recordMovement(int $variantId, int $delta, InventoryReason $reason, ?string $note = null, ?string $referenceType = null, ?int $referenceId = null): void
    {
        DB::transaction(function () use ($variantId, $delta, $reason, $note, $referenceType, $referenceId) {
            VariantStock::where('variant_id', $variantId)->increment('on_hand', $delta);
            VariantStock::where('variant_id', $variantId)->update(['updated_at' => now()]);

            InventoryMovement::create([
                'variant_id' => $variantId,
                'delta' => $delta,
                'reason' => $reason->value,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'created_at' => now(),
            ]);
        });
    }
}
