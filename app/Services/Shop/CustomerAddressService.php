<?php

namespace App\Services\Shop;

use App\Models\Shop\Customer;
use App\Models\Shop\CustomerAddress;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerAddressService
{
    public function create(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data) {
            $address = CustomerAddress::create([
                'site_id' => $customer->site_id,
                'customer_id' => $customer->id,
                'label' => $data['label'] ?? null,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'line1' => $data['line1'],
                'line2' => $data['line2'] ?? null,
                'city' => $data['city'],
                'region' => $data['region'] ?? null,
                'postcode' => $data['postcode'],
                'country_code' => strtoupper($data['country_code']),
                'is_default_shipping' => false,
                'is_default_billing' => false,
            ]);

            if (! empty($data['is_default_shipping'])) {
                $this->setDefault($address, 'shipping');
            }
            if (! empty($data['is_default_billing'])) {
                $this->setDefault($address, 'billing');
            }

            return $address->refresh();
        });
    }

    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data) {
            $address->update([
                'label' => array_key_exists('label', $data) ? $data['label'] : $address->label,
                'name' => $data['name'] ?? $address->name,
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : $address->phone,
                'line1' => $data['line1'] ?? $address->line1,
                'line2' => array_key_exists('line2', $data) ? $data['line2'] : $address->line2,
                'city' => $data['city'] ?? $address->city,
                'region' => array_key_exists('region', $data) ? $data['region'] : $address->region,
                'postcode' => $data['postcode'] ?? $address->postcode,
                'country_code' => isset($data['country_code'])
                    ? strtoupper($data['country_code'])
                    : $address->country_code,
            ]);

            if (! empty($data['is_default_shipping'])) {
                $this->setDefault($address, 'shipping');
            }
            if (! empty($data['is_default_billing'])) {
                $this->setDefault($address, 'billing');
            }

            return $address->refresh();
        });
    }

    public function delete(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address) {
            $customer = Customer::query()
                ->where('id', $address->customer_id)
                ->where('site_id', $address->site_id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasDefaultShipping = $address->is_default_shipping;
            $wasDefaultBilling = $address->is_default_billing;

            $address->delete();

            if ($wasDefaultShipping) {
                $this->promoteDefault($customer, 'shipping');
            }
            if ($wasDefaultBilling) {
                $this->promoteDefault($customer, 'billing');
            }
        });
    }

    public function setDefault(CustomerAddress $address, string $kind): void
    {
        $column = $this->columnForKind($kind);

        DB::transaction(function () use ($address, $column) {
            CustomerAddress::query()
                ->where('site_id', $address->site_id)
                ->where('customer_id', $address->customer_id)
                ->lockForUpdate()
                ->update([$column => false]);

            $address->update([$column => true]);
        });
    }

    private function promoteDefault(Customer $customer, string $kind): void
    {
        $column = $this->columnForKind($kind);

        $next = CustomerAddress::query()
            ->forCustomer($customer)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($next) {
            $next->update([$column => true]);
        }
    }

    private function columnForKind(string $kind): string
    {
        return match ($kind) {
            'shipping' => 'is_default_shipping',
            'billing' => 'is_default_billing',
            default => throw new InvalidArgumentException("Unknown address default kind [{$kind}]."),
        };
    }
}
