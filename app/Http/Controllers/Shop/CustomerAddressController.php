<?php

namespace App\Http\Controllers\Shop;

use App\Models\Shop\CustomerAddress;
use App\Services\Shop\CustomerAddressService;
use Illuminate\Http\Request;

class CustomerAddressController
{
    public function __construct(protected CustomerAddressService $addresses) {}

    public function index(Request $request)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        $list = CustomerAddress::query()
            ->forCustomer($customer)
            ->orderByDesc('is_default_shipping')
            ->orderByDesc('updated_at')
            ->get();

        return view('shop.account.addresses', [
            'site' => $request->attributes->get('resolved_site'),
            'customer' => $customer,
            'addresses' => $list,
        ]);
    }

    public function store(Request $request)
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        $this->addresses->create($customer, $this->validated($request));

        return redirect()->route('shop.account.addresses');
    }

    public function update(Request $request, int $id)
    {
        $address = $this->addressFor($id);
        $this->addresses->update($address, $this->validated($request));

        return redirect()->route('shop.account.addresses');
    }

    public function destroy(int $id)
    {
        $this->addresses->delete($this->addressFor($id));

        return redirect()->route('shop.account.addresses');
    }

    public function setDefault(int $id, string $kind)
    {
        $this->addresses->setDefault($this->addressFor($id), $kind);

        return redirect()->route('shop.account.addresses');
    }

    private function addressFor(int $id): CustomerAddress
    {
        $customer = auth('customer')->user();
        abort_unless($customer, 401);

        return CustomerAddress::query()
            ->forCustomer($customer)
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => 'nullable|string|max:40',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:64',
            'line1' => 'required|string|max:255',
            'line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'region' => 'nullable|string|max:120',
            'postcode' => 'required|string|max:16',
            'country_code' => 'required|string|size:2',
            'is_default_shipping' => 'sometimes|boolean',
            'is_default_billing' => 'sometimes|boolean',
        ]);
    }
}
