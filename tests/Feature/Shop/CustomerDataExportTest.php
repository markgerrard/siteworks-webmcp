<?php

use App\Jobs\Shop\BuildCustomerDataExport;
use App\Mail\Shop\DataExportReadyMail;
use App\Models\Shop\Customer;
use App\Models\Shop\CustomerAddress;
use App\Models\Shop\Order;
use App\Models\Site;
use App\Services\Shop\CustomerAddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('job generates JSON export and emails signed link', function () {
    Mail::fake();
    Storage::fake('exports');

    $site = Site::factory()->create();
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'me@x.com', 'email_verified_at' => now()]);
    Order::create([
        'site_id' => $site->id, 'number' => 'E-1', 'customer_id' => $customer->id,
        'email' => 'me@x.com', 'name' => 'Me',
        'status' => 'paid', 'refund_status' => 'none',
        'subtotal_cents' => 100, 'shipping_cents' => 0, 'tax_cents' => 0,
        'shipping_tax_cents' => 0, 'total_cents' => 100,
        'tax_country_code' => 'GB', 'shipping_address_json' => [],
        'shipping_method_label' => 'Std', 'placed_at' => now(),
    ]);

    (new BuildCustomerDataExport($customer->id))->handle();

    Mail::assertQueued(DataExportReadyMail::class, fn ($m) => $m->hasTo('me@x.com'));
});

test('the data export includes saved addresses', function () {
    Mail::fake();
    Storage::fake('exports');

    $site = Site::factory()->create();
    $customer = Customer::create(['site_id' => $site->id, 'email' => 'me@x.com', 'email_verified_at' => now(), 'name' => 'Me']);
    app(CustomerAddressService::class)->create($customer, [
        'label' => 'Home',
        'name' => 'Me',
        'phone' => '01234 567890',
        'line1' => '14 Rose Lane',
        'line2' => null,
        'city' => 'Lancaster',
        'region' => null,
        'postcode' => 'LA1 1AA',
        'country_code' => 'GB',
    ]);

    (new BuildCustomerDataExport($customer->id))->handle();

    $files = Storage::disk('exports')->allFiles();
    expect($files)->toHaveCount(1);

    $payload = json_decode(Storage::disk('exports')->get($files[0]), true);
    expect($payload['addresses'])->toHaveCount(1)
        ->and($payload['addresses'][0]['line1'])->toBe('14 Rose Lane')
        ->and($payload['addresses'][0]['postcode'])->toBe('LA1 1AA')
        ->and($payload['addresses'][0]['country_code'])->toBe('GB')
        ->and($payload['addresses'][0]['label'])->toBe('Home');
});
