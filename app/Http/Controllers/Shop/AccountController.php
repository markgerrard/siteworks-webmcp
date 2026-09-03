<?php

namespace App\Http\Controllers\Shop;

use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Models\SiteEnquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AccountController
{
    public function index(Request $request)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        $site = $request->attributes->get('resolved_site');

        return view('shop.account.index', [
            'site' => $site,
            'customer' => $customer,
            'enquiries' => $this->enquiriesFor($site, $customer),
        ]);
    }

    public function enquiries(Request $request)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        $site = $request->attributes->get('resolved_site');
        abort_unless($site && $site->shopShowsEnquiries(), 404);

        return view('shop.account.enquiries', [
            'site' => $site,
            'customer' => $customer,
            'enquiries' => $this->enquiriesFor($site, $customer),
        ]);
    }

    public function orders(Request $request)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        $site = $request->attributes->get('resolved_site');
        abort_unless($site && $site->shopShowsAccountOrders(), 404);

        $orders = Order::where('customer_id', $customer->id)
            ->orderByDesc('placed_at')
            ->paginate(20);

        return view('shop.account.orders', [
            'site' => $site,
            'orders' => $orders,
        ]);
    }

    public function order(Request $request, int $orderId)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        $site = $request->attributes->get('resolved_site');
        abort_unless($site && $site->shopShowsAccountOrders(), 404);

        $order = Order::where('customer_id', $customer->id)->findOrFail($orderId);
        $order->load('items');

        return view('shop.account.order', [
            'site' => $site,
            'order' => $order,
        ]);
    }

    /**
     * @return Collection<int, SiteEnquiry>
     */
    private function enquiriesFor(?Site $site, Customer $customer): Collection
    {
        if (! $site?->shopShowsEnquiries()) {
            return collect();
        }

        return SiteEnquiry::query()
            ->forCustomer($customer)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
