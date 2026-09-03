<?php

namespace App\Services\Shop;

use App\Enums\Shop\RefundStatus;
use App\Exceptions\Shop\OrderStateException;
use App\Jobs\Shop\DispatchOrderRefunded;
use App\Models\Shop\Order;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(protected object $stripeRefundGateway) {}

    public function refundFull(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // Lock the row before reading refund_amount_cents. Without this, two
            // concurrent refunds (a double-clicked button) both read the same
            // pre-refund figure, both pass the guard below, and both call Stripe —
            // refunding twice while recording once.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $outstanding = $locked->total_cents - $locked->refund_amount_cents;
            if ($outstanding <= 0) {
                throw new OrderStateException('Already refunded in full');
            }

            $this->stripeRefundGateway->refund(
                $locked->stripe_payment_intent_id,
                $outstanding,
                $this->idempotencyKey($locked, $outstanding, 'full'),
            );

            $locked->update([
                'refund_status' => RefundStatus::Full->value,
                'refund_amount_cents' => $locked->total_cents,
            ]);
        });

        DispatchOrderRefunded::dispatch($order->id);
    }

    public function refundPartial(Order $order, int $amountCents): void
    {
        if ($amountCents >= $order->total_cents) {
            throw new OrderStateException('Use refundFull for full amount');
        }

        DB::transaction(function () use ($order, $amountCents) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $newTotal = $locked->refund_amount_cents + $amountCents;
            if ($newTotal > $locked->total_cents) {
                throw new OrderStateException('Refund would exceed order total');
            }

            $this->stripeRefundGateway->refund(
                $locked->stripe_payment_intent_id,
                $amountCents,
                $this->idempotencyKey($locked, $amountCents, 'partial'),
            );

            $locked->update([
                'refund_status' => RefundStatus::Partial->value,
                'refund_amount_cents' => $newTotal,
            ]);
        });

        DispatchOrderRefunded::dispatch($order->id);
    }

    /**
     * Stable per-attempt key: the same order at the same already-refunded position,
     * for the same amount, is the SAME refund. A retry reuses the key and Stripe
     * returns the original refund instead of moving money again. A genuine second,
     * later partial refund has a different refund_amount_cents and so a different key.
     */
    private function idempotencyKey(Order $order, int $amountCents, string $kind): string
    {
        return 'refund:'.$order->id.':'.$kind.':'.$order->refund_amount_cents.':'.$amountCents;
    }
}
