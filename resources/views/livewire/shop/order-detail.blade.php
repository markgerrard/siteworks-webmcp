<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Shop\Order;
use App\Services\Shop\OrderService;
use App\Services\Shop\RefundService;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    #[Locked]
    public int $orderId;

    public string $trackingNumber = '';

    public string $trackingCarrier = '';

    public float $refundAmountPounds = 0;

    public string $note = '';

    public function mount(int $siteId, int $orderId): void
    {
        $this->siteId = $siteId;
        $this->orderId = $orderId;
        $this->abortUnlessShopEstablished();
    }

    public function markShipped(OrderService $orderService): void
    {
        $this->abortUnlessShopEstablished();
        $order = Order::where('site_id', $this->siteId)->findOrFail($this->orderId);
        $orderService->markShipped(
            $order,
            trackingNumber: $this->trackingNumber ?: null,
            trackingCarrier: $this->trackingCarrier ?: null,
        );
    }

    public function cancelOrder(OrderService $orderService): void
    {
        $this->abortUnlessShopEstablished();
        $order = Order::where('site_id', $this->siteId)->findOrFail($this->orderId);
        $orderService->cancel($order);
    }

    public function refundFull(RefundService $refundService): void
    {
        $this->abortUnlessShopEstablished();
        $order = Order::where('site_id', $this->siteId)->findOrFail($this->orderId);
        $refundService->refundFull($order);
    }

    public function refundPartial(RefundService $refundService): void
    {
        $this->abortUnlessShopEstablished();
        $order = Order::where('site_id', $this->siteId)->findOrFail($this->orderId);
        $refundService->refundPartial($order, (int) round($this->refundAmountPounds * 100));
    }

    public function saveNote(): void
    {
        $this->abortUnlessShopEstablished();
        Order::where('site_id', $this->siteId)->where('id', $this->orderId)
            ->update(['notes_internal' => $this->note]);
    }

    public function with(): array
    {
        return [
            'order' => Order::where('site_id', $this->siteId)
                ->with(['items.variant.product'])
                ->findOrFail($this->orderId),
        ];
    }
}; ?>

<div class="space-y-6 max-w-3xl">
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="xl">Order {{ $order->number }}</flux:heading>
        <flux:badge size="sm" :color="match ($order->status) {
            \App\Enums\Shop\OrderStatus::Paid => 'sky',
            \App\Enums\Shop\OrderStatus::Shipped => 'green',
            \App\Enums\Shop\OrderStatus::Cancelled => 'zinc',
            default => 'zinc',
        }">{{ ucfirst($order->status->value) }}</flux:badge>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-neutral-700 p-4">
        <div><strong>{{ $order->name }}</strong> — {{ $order->email }}</div>
        @if ($order->phone)
            <div>{{ $order->phone }}</div>
        @endif
        <div class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            {{ $order->shipping_address_json['line1'] ?? '' }}
            @if (! empty($order->shipping_address_json['line2'])), {{ $order->shipping_address_json['line2'] }}@endif,
            {{ $order->shipping_address_json['city'] ?? '' }} {{ $order->shipping_address_json['postcode'] ?? '' }}
        </div>
    </div>

    <div>
        <h2 class="font-semibold mb-2">Items</h2>
        @if ($order->items->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">No line items on this order.</p>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Item</flux:table.column>
                    <flux:table.column>Qty</flux:table.column>
                    <flux:table.column>Line total</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($order->items as $item)
                        <flux:table.row>
                            <flux:table.cell>
                                {{ $item->product_name_snapshot }}
                                @if ($item->variant_label_snapshot)
                                    — {{ $item->variant_label_snapshot }}
                                @endif
                                @include('shop.partials.line-personalisation', [
                                    'personalisation' => $item->personalisation,
                                    'site' => $order->site,
                                    'audience' => 'session', // web surface: session/staff-scoped, short TTL
                                ])
                            </flux:table.cell>
                            <flux:table.cell>{{ $item->qty }}</flux:table.cell>
                            <flux:table.cell class="font-mono">{{ \App\Support\ShopMoney::format((int) ($item->line_total_cents), ($order->site?->shop_currency ?? 'GBP')) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
        <div class="mt-3 space-y-1 text-right">
            <div>Subtotal {{ \App\Support\ShopMoney::format((int) ($order->subtotal_cents), ($order->site?->shop_currency ?? 'GBP')) }}</div>
            <div>Shipping {{ \App\Support\ShopMoney::format((int) ($order->shipping_cents), ($order->site?->shop_currency ?? 'GBP')) }}</div>
            <div>VAT {{ \App\Support\ShopMoney::format((int) (($order->tax_cents + $order->shipping_tax_cents)), ($order->site?->shop_currency ?? 'GBP')) }}</div>
            <div class="font-semibold">Total {{ \App\Support\ShopMoney::format((int) ($order->total_cents), ($order->site?->shop_currency ?? 'GBP')) }}</div>
            @if ($order->refund_amount_cents > 0)
                <div class="text-sm text-zinc-600 dark:text-zinc-300">Refunded {{ \App\Support\ShopMoney::format((int) ($order->refund_amount_cents), ($order->site?->shop_currency ?? 'GBP')) }}</div>
            @endif
        </div>
    </div>

    @if ($order->status === App\Enums\Shop\OrderStatus::Paid)
        <div class="rounded-xl border border-zinc-200 dark:border-neutral-700 p-4 space-y-3">
            <h3 class="font-semibold">Dispatch</h3>
            <flux:input wire:model="trackingNumber" placeholder="Tracking number (optional)" />
            <flux:input wire:model="trackingCarrier" placeholder="Courier (e.g. Royal Mail)" />
            <div class="flex flex-wrap gap-2">
                <flux:button
                    variant="primary"
                    wire:click="markShipped"
                    wire:confirm="Mark this order as shipped? This moves stock out as dispatched."
                    wire:target="markShipped"
                >Mark shipped</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="cancelOrder"
                    wire:confirm="Cancel this order? This restores stock and cannot be undone from here."
                    wire:target="cancelOrder"
                >Cancel</flux:button>
            </div>
        </div>
    @endif

    @if ($order->status !== App\Enums\Shop\OrderStatus::Pending)
        <div class="rounded-xl border border-zinc-200 dark:border-neutral-700 p-4 space-y-3">
            <h3 class="font-semibold">Refund</h3>
            <flux:input type="number" step="0.01" wire:model="refundAmountPounds" placeholder="Partial refund amount ({{ \App\Support\ShopMoney::symbol(($order->site?->shop_currency ?? 'GBP')) }})" />
            <div class="flex flex-wrap gap-2">
                <flux:button
                    wire:click="refundPartial"
                    wire:confirm="Issue a partial refund? This returns money to the customer."
                    wire:target="refundPartial"
                >Refund partial</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="refundFull"
                    wire:confirm="Refund this order in full? This returns the money and cannot be undone from here."
                    wire:target="refundFull"
                >Refund full</flux:button>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 dark:border-neutral-700 p-4 space-y-3">
        <h3 class="font-semibold">Internal note</h3>
        <flux:textarea wire:model="note" rows="3">{{ $order->notes_internal }}</flux:textarea>
        <flux:button wire:click="saveNote" wire:target="saveNote">Save note</flux:button>
    </div>
</div>
