<?php

namespace App\Console\Commands\Shop;

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\StockReservation;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePendingOrders extends Command
{
    protected $signature = 'shop:expire-pending-orders';

    protected $description = 'Cancel pending orders past expires_at; release reservations';

    public function handle(StockService $stock): int
    {
        // Reap on expires_at + grace, NOT expires_at: the payment cutoff and our right
        // to cancel are different deadlines. See CheckoutService::REAP_GRACE_MINUTES.
        $expired = Order::where('status', OrderStatus::Pending->value)
            ->where('expires_at', '<', now()->subMinutes(CheckoutService::REAP_GRACE_MINUTES))
            ->get();

        $count = 0;
        foreach ($expired as $order) {
            $count += $this->reapOrder($order->id, $stock) ? 1 : 0;
        }

        $this->info('Expired '.$count.' orders.');

        return self::SUCCESS;
    }

    /**
     * Cancel ONE pending order and release its stock, atomically, under a row lock.
     *
     * The select above is a snapshot; between it and the write, the Stripe webhook may
     * have flipped this order to Paid and committed its stock. Writing Cancelled through
     * the stale model overwrote that: a charged customer, committed inventory, and an
     * order erased from fulfilment. So the status and the deadline are re-checked under
     * the same lock the webhook now takes, and the write is conditional on Pending.
     * Returns false when the order was no longer ours to cancel.
     */
    public function reapOrder(int $orderId, StockService $stock): bool
    {
        return DB::transaction(function () use ($orderId, $stock) {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            $deadline = now()->subMinutes(CheckoutService::REAP_GRACE_MINUTES);

            if (! $order || $order->status !== OrderStatus::Pending || $order->expires_at >= $deadline) {
                return false;
            }

            $affected = Order::whereKey($orderId)
                ->where('status', OrderStatus::Pending->value)
                ->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

            if ($affected !== 1) {
                return false;
            }

            $reservations = StockReservation::where('order_id', $orderId)
                ->whereNull('released_at')->whereNull('committed_at')
                ->lockForUpdate()->get();
            foreach ($reservations as $r) {
                $stock->release($r->id, 'pending_expired');
            }

            return true;
        });
    }
}
