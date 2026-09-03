<?php

namespace App\Jobs\Shop;

use App\Mail\Shop\DataExportReadyMail;
use App\Models\Shop\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class BuildCustomerDataExport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $customerId) {}

    public function handle(): void
    {
        $customer = Customer::with(['orders.items', 'addresses'])->find($this->customerId);
        if (! $customer) {
            return;
        }

        $payload = [
            'customer' => $customer->only(['id', 'email', 'name', 'created_at']),
            'addresses' => $customer->addresses->map(fn ($a) => [
                'label' => $a->label,
                'name' => $a->name,
                'phone' => $a->phone,
                'line1' => $a->line1,
                'line2' => $a->line2,
                'city' => $a->city,
                'region' => $a->region,
                'postcode' => $a->postcode,
                'country_code' => $a->country_code,
                'is_default_shipping' => $a->is_default_shipping,
                'is_default_billing' => $a->is_default_billing,
            ])->toArray(),
            'orders' => $customer->orders->map(fn ($o) => [
                'number' => $o->number,
                'placed_at' => $o->placed_at,
                'status' => $o->status->value,
                'total_cents' => $o->total_cents,
                'items' => $o->items->map(fn ($i) => [
                    'name' => $i->product_name_snapshot,
                    'qty' => $i->qty,
                    'line_total_cents' => $i->line_total_cents,
                ]),
            ])->toArray(),
        ];

        $filename = "customer-{$customer->id}-".now()->format('Ymd_His').'.json';
        Storage::disk('exports')->put($filename, json_encode($payload, JSON_PRETTY_PRINT));

        $signedUrl = URL::temporarySignedRoute(
            'shop.account.download-export',
            now()->addDays(7),
            ['filename' => $filename, 'customer' => $customer->id]
        );

        Mail::to($customer->email)->queue(new DataExportReadyMail($customer, $signedUrl));
    }
}
