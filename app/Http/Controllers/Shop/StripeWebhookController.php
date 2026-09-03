<?php

namespace App\Http\Controllers\Shop;

use App\Enums\Shop\OrderStatus;
use App\Models\Shop\Order;
use App\Models\Shop\StockReservation;
use App\Models\Shop\WebhookEvent;
use App\Services\Shop\StockService;
use App\Services\Shop\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookController
{
    public function __construct(
        protected StripeService $stripe,
        protected StockService $stockService,
    ) {}

    /** Set by a handler that reached a terminal-but-abnormal outcome; persisted on the event. */
    private ?string $terminalError = null;

    public function __invoke(Request $request)
    {
        // In tests, skip signature verification; in production use $this->stripe->constructEvent()
        if (app()->environment('testing')) {
            $event = $request->all();
        } else {
            try {
                $event = json_decode(json_encode(
                    $this->stripe->constructEvent(
                        $request->getContent(),
                        $request->header('Stripe-Signature', '')
                    )
                ), true);
            } catch (\Throwable $e) {
                return response('Invalid signature', 400);
            }
        }

        $eventId = $event['id'];
        $type = $event['type'];
        $payload = $event;

        // Idempotency guard.
        //
        // This tests processed_at, NOT row existence. The row is written before the
        // handler runs (so a crash mid-handler still leaves a record), which means
        // "a row exists" only tells us delivery was ATTEMPTED, not that it succeeded.
        // Guarding on existence meant a transient failure returned 500, Stripe retried,
        // and the retry short-circuited as a duplicate — the payment was never
        // processed, the order stayed pending, and ExpirePendingOrders cancelled it
        // while the customer had been charged.
        $existing = WebhookEvent::find($eventId);
        if ($existing && $existing->processed_at !== null) {
            return response('OK (duplicate)', 200);
        }

        if (! $existing) {
            WebhookEvent::create([
                'stripe_event_id' => $eventId,
                'event_type' => $type,
                'payload_json' => $payload,
                'received_at' => now(),
            ]);
        }

        try {
            $this->terminalError = null;
            $this->dispatch($type, $payload);
            WebhookEvent::where('stripe_event_id', $eventId)->update([
                'processed_at' => now(),
                'error' => $this->terminalError,
            ]);
        } catch (\Throwable $e) {
            WebhookEvent::where('stripe_event_id', $eventId)->update(['error' => $e->getMessage()]);
            Log::error('Stripe webhook handler failed: '.$e->getMessage());

            return response('Error', 500);
        }

        return response('OK', 200);
    }

    private function dispatch(string $type, array $payload): void
    {
        match ($type) {
            'checkout.session.completed' => $this->handlePaid($payload),
            default => null, // ignore unknown types
        };
    }

    private function handlePaid(array $payload): void
    {
        $session = $payload['data']['object'];
        $orderId = (int) ($session['metadata']['order_id'] ?? 0);

        DB::transaction(function () use ($session, $orderId) {
            // Take the order row lock BEFORE the conditional transition. The reaper takes
            // the same lock, so reaper-vs-webhook is now serialised at one seam instead of
            // two unlocked read-then-writes racing each other.
            Order::whereKey($orderId)->lockForUpdate()->first();

            $affected = Order::where('id', $orderId)
                ->where('status', OrderStatus::Pending->value)
                ->update([
                    'status' => OrderStatus::Paid->value,
                    'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
                    'paid_at' => now(),
                ]);

            if ($affected === 0) {
                // "already paid" and "cancelled" are NOT the same thing, and collapsing
                // them into one silent no-op is how a real payment disappears: the
                // customer was charged, the order sits cancelled, and we return 200 so
                // Stripe never retries and nothing alerts.
                $current = Order::find($orderId);

                if ($current && $current->status === OrderStatus::Cancelled) {
                    // A real payment for an order we already cancelled. This must be
                    // TERMINAL: throwing here would make every Stripe retry repeat the
                    // same failure forever with no way to make progress. Record
                    // the payment identity on the order so a human can refund, mark the
                    // event terminal with a distinct error, alert, and ACKNOWLEDGE. An
                    // automated refund of a cancelled order is a product decision, not
                    // something a webhook handler should decide on its own.
                    Order::whereKey($orderId)->update([
                        'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
                    ]);
                    Log::critical('Stripe payment received for a CANCELLED order — needs manual reconciliation', [
                        'order_id' => $orderId,
                        'payment_intent' => $session['payment_intent'] ?? null,
                        'cancelled_at' => $current->cancelled_at,
                    ]);
                    $this->terminalError = 'payment_after_cancellation';

                    return;
                }

                return; // genuinely already paid: idempotent no-op
            }

            $reservations = StockReservation::where('order_id', $orderId)
                ->whereNull('released_at')
                ->whereNull('committed_at')
                ->get();

            foreach ($reservations as $res) {
                $this->stockService->commit($res->id);
            }

            // Dispatch confirmation emails (async jobs)
            \App\Jobs\Shop\DispatchOrderConfirmation::dispatch($orderId);
            \App\Jobs\Shop\DispatchMerchantNewOrder::dispatch($orderId);
        });
    }
}
