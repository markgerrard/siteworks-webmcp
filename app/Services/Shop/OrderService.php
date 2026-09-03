<?php

namespace App\Services\Shop;

use App\Enums\Shop\OrderStatus;
use App\Exceptions\Shop\OrderStateException;
use App\Jobs\Shop\DispatchOrderCancelled;
use App\Jobs\Shop\DispatchOrderShipped;
use App\Models\Shop\Order;
use App\Models\Shop\StockReservation;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(protected StockService $stock) {}

    public function markShipped(Order $order, ?string $trackingNumber = null, ?string $trackingCarrier = null): void
    {
        if ($order->status !== OrderStatus::Paid) {
            throw new OrderStateException("Cannot ship order in status {$order->status->value}");
        }

        $order->update([
            'status' => OrderStatus::Shipped->value,
            'shipped_at' => now(),
            'tracking_number' => $trackingNumber,
            'tracking_carrier' => $trackingCarrier,
        ]);

        DispatchOrderShipped::dispatch($order->id);
    }

    public function cancel(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Paid], true)) {
            throw new OrderStateException("Cannot cancel order in status {$order->status->value}");
        }

        // Status change and stock release are ONE transaction. Cancelling used to set
        // status only, leaving the reservation attached — and since an order-attached
        // reservation is exempt from the cart TTL, that stock was withheld forever with
        // no scheduled path to recover it (ExpirePendingOrders selects status=Pending,
        // so it can never see a cancelled order).
        //
        // Only UNCOMMITTED reservations are released here. A paid order's stock is
        // committed and its restock is a refund-policy decision, deliberately not
        // conflated with this case.
        DB::transaction(function () use ($order) {
            $order->update([
                'status' => OrderStatus::Cancelled->value,
                'cancelled_at' => now(),
            ]);

            $reservations = StockReservation::where('order_id', $order->id)
                ->whereNull('released_at')
                ->whereNull('committed_at')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $this->stock->release($reservation->id, 'order_cancelled');
            }
        });

        DispatchOrderCancelled::dispatch($order->id);
    }
}
