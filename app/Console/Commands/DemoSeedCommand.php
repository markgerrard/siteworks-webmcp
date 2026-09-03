<?php

namespace App\Console\Commands;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Demo\CaminoCatalogueSeeder;
use App\Services\Demo\CaminoQuoteSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Idempotently import the Camino Bakehouse demo site and seed the portal login.';

    public function handle(): int
    {
        $siteId = 64;
        $bundlePath = database_path('demo/site64');
        $from = '{{DEMO_MEDIA}}/'; // media placeholder written into the seed bundle at export time
        // The bundle's media lands on the public disk, so its references take the
        // address that disk emits: in the demo a root-relative /storage path, which
        // the portal host and the storefront host each serve as their own.
        $to = rtrim(Storage::disk('public')->url(''), '/').'/';

        if (! Site::query()->where('id', $siteId)->exists()) {
            $this->call('site:import-bundle', [
                'path' => $bundlePath,
                '--disk' => 'public',
                '--rewrite' => [$from.'='.$to],
            ]);
        } else {
            $this->line("Site id={$siteId} already present — skipping import.");
        }

        $client = Client::query()->firstOrCreate(
            ['slug' => 'camino-bakehouse'],
            ['name' => 'Camino Bakehouse'],
        );
        if ($client->name !== 'Camino Bakehouse') {
            $client->forceFill(['name' => 'Camino Bakehouse'])->save();
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error("Site id={$siteId} was not imported.");

            return self::FAILURE;
        }

        $site->forceFill([
            'preview_domain' => (string) config('demo.site_host', 'localhost'),
            'custom_domain' => null,
            'custom_domain_status' => null,
            'client_id' => $client->id,
        ])->save();

        $profile = BusinessProfile::query()->where('site_id', $siteId)->first();
        if ($profile) {
            $data = $profile->profile_data ?? [];
            $data['watermark_enabled'] = false;
            $profile->update(['profile_data' => $data]);
        }

        $preview = $site->latestPreview;
        if ($preview && is_array($preview->snapshot)) {
            $snapshot = $preview->snapshot;
            $snapshot['watermark_enabled'] = false;
            if (isset($snapshot['profile']) && is_array($snapshot['profile'])) {
                $snapshot['profile']['watermark_enabled'] = false;
            }
            $preview->update(['snapshot' => $snapshot]);
        }

        $email = (string) config('demo.user_email');
        $password = (string) config('demo.user_password');
        $user = User::query()->withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => 'Camino Bakehouse',
            'password' => $password,
            'client_id' => $client->id,
            'role' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'remember_token' => $user->remember_token ?: Str::random(10),
            'deleted_at' => null,
        ])->save();

        $loginUrl = rtrim((string) config('app.url'), '/').'/login';
        $this->newLine();
        // Photographed starter catalogue plus an empty Seasonal — Fall category:
        // the storefront has product rows, and import_products still has a
        // destination to fill.
        app(CaminoCatalogueSeeder::class)->seed($site, $bundlePath);
        $this->line('  seeded starter catalogue');
        app(CaminoQuoteSettings::class)->seed($site);
        $this->line('  configured quote pickup and delivery');

        $this->info('Demo portal login');
        $this->line("  URL:      {$loginUrl}");
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$password}");
        $this->line('  Storefront: http://'.config('demo.site_host').':8090/');

        return self::SUCCESS;
    }
}
