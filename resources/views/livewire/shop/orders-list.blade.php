<?php

use App\Enums\Shop\OrderStatus;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Shop\Order;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesSiteAccess, WithPagination;

    #[Locked]
    public int $siteId;

    #[Locked]
    public string $routeName = 'shop.admin.orders.show';

    #[Locked]
    public ?int $productId = null;

    public string $statusFilter = 'paid';

    public string $search = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        abort_unless($this->findAuthorizedSite(), 403);
        if ($this->productId === null && request()->filled('product')) {
            $this->productId = (int) request('product');
        }
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $q = Order::where('site_id', $this->siteId)
            ->where('status', '!=', OrderStatus::Pending->value);

        if ($this->statusFilter) {
            $q->where('status', $this->statusFilter);
        }

        if ($this->productId) {
            $q->whereHas('items', fn ($query) => $query->where('product_id', $this->productId));
        }

        if ($this->search !== '') {
            $q->where(function ($q) {
                $q->where('number', 'ILIKE', "%{$this->search}%")
                    ->orWhere('email', 'ILIKE', "%{$this->search}%");
            });
        }

        return ['orders' => $q->withCount('items')->orderByDesc('placed_at')->paginate(20)];
    }
}; ?>
@php $shopCurrency = \App\Models\Site::query()->whereKey($this->siteId)->value('shop_currency') ?? 'GBP'; @endphp

<div class="space-y-4">
    <div class="flex flex-col md:flex-row gap-2">
        <flux:input type="search" wire:model.live.debounce.300ms="search" placeholder="Search order # or email" class="flex-1" />
        <flux:select wire:model.live="statusFilter" class="md:w-64">
            <flux:select.option value="paid">Paid (awaiting dispatch)</flux:select.option>
            <flux:select.option value="shipped">Shipped</flux:select.option>
            <flux:select.option value="cancelled">Cancelled</flux:select.option>
            <flux:select.option value="">All</flux:select.option>
        </flux:select>
    </div>

    <p wire:loading class="text-sm text-zinc-500 dark:text-zinc-400">Loading…</p>

    @if ($orders->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-12 dark:border-neutral-700">
            <flux:heading size="lg">No orders yet</flux:heading>
            <flux:subheading class="mt-1">Paid orders will appear here after checkout.</flux:subheading>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Order</flux:table.column>
                <flux:table.column>Customer</flux:table.column>
                <flux:table.column>Total</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($orders as $o)
                    <flux:table.row wire:key="order-{{ $o->id }}">
                        <flux:table.cell class="font-medium">
                            <a href="{{ route($routeName, ['site' => $siteId, 'order' => $o->id]) }}" class="hover:underline">{{ $o->number }}</a>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div>{{ $o->name }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $o->email }}</div>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono">
                            {{ \App\Support\ShopMoney::format((int) ($o->total_cents), $shopCurrency) }}
                            <span class="text-zinc-500">· {{ $o->items_count }} item(s)</span>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="match ($o->status) {
                                \App\Enums\Shop\OrderStatus::Paid => 'sky',
                                \App\Enums\Shop\OrderStatus::Shipped => 'green',
                                \App\Enums\Shop\OrderStatus::Cancelled => 'zinc',
                                default => 'zinc',
                            }">{{ ucfirst($o->status->value) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button variant="ghost" size="sm" :href="route($routeName, ['site' => $siteId, 'order' => $o->id])" icon-trailing="chevron-right">
                                    View
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div>{{ $orders->links() }}</div>
    @endif
</div>
