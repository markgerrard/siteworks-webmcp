<?php

namespace App\Support\Shop;

use App\Enums\Shop\RefundStatus;
use App\Models\Shop\Order;

final class OrderTimeline
{
    /**
     * @return list<array{key: string, label: string, at: mixed, done: bool}>
     */
    public static function for(Order $order): array
    {
        $refunded = $order->refund_status === RefundStatus::Partial
            || $order->refund_status === RefundStatus::Full;
        $dispatched = $order->shipped_at !== null || filled($order->tracking_number);

        return [
            [
                'key' => 'placed',
                'label' => 'Placed',
                'at' => $order->placed_at,
                'done' => $order->placed_at !== null,
            ],
            [
                'key' => 'paid',
                'label' => 'Paid',
                'at' => $order->paid_at,
                'done' => $order->paid_at !== null,
            ],
            [
                'key' => 'dispatched',
                'label' => 'Dispatched',
                'at' => $order->shipped_at,
                'done' => $dispatched,
            ],
            [
                'key' => 'refunded',
                'label' => 'Refunded',
                'at' => $refunded ? $order->updated_at : null,
                'done' => $refunded,
            ],
        ];
    }
}
