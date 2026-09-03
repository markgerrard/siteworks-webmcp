<?php

namespace App\Console\Commands\Site;

use App\Models\Site;
use App\Services\Site\CountryResolver;
use Illuminate\Console\Command;

class BackfillSiteCountry extends Command
{
    protected $signature = 'site:backfill-country {--dry-run} {--site=} {--overwrite : Overwrite sites that already have country set}';

    protected $description = 'Run CountryResolver against every site (or a specific --site=ID) and write the result into sites.country. Skips sites that already have a value unless --overwrite is given.';

    public function handle(CountryResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        // chunkById() + with('businessProfile') keeps memory + query
        // count linear at 10k+ row scale: the resolver reads
        // $site->businessProfile?->profile_data, so without eager-load
        // every iteration would issue a fresh query.
        //
        // Idempotent across reruns: each row is updated individually,
        // the resolver is deterministic, and the --skip-when-set
        // behaviour means a killed run can be safely re-invoked without
        // double-writing.
        $query = Site::with('businessProfile');
        if ($this->option('site')) {
            $query->where('id', $this->option('site'));
        }

        $written = 0;
        $skipped = 0;
        $unchanged = 0;
        $scanned = 0;
        $tally = [];

        $process = function ($site) use ($resolver, $dryRun, $overwrite, &$written, &$skipped, &$unchanged, &$scanned, &$tally) {
            $scanned++;
            $existing = $site->country;
            $resolved = $resolver->resolveLabel($site);
            $tally[$resolved] = ($tally[$resolved] ?? 0) + 1;

            if ($existing !== null && $existing !== '' && ! $overwrite) {
                $this->line("site_id={$site->id} ({$site->business_name}): already set ({$existing}) — skipping");
                $skipped++;

                return;
            }

            if ($existing === $resolved) {
                $unchanged++;

                return;
            }

            $this->line("site_id={$site->id} ({$site->business_name}): {$existing} → {$resolved}".($dryRun ? ' (dry-run)' : ''));

            if (! $dryRun) {
                $site->update(['country' => $resolved]);
            }
            $written++;
        };

        $query->chunkById(500, function ($sites) use ($process) {
            $sites->each($process);
        });

        $this->newLine();
        $this->info("Total scanned: {$scanned}");
        $this->info("Written:   {$written}".($dryRun ? ' (would-be — dry-run mode)' : ''));
        $this->info("Skipped:   {$skipped} (already set)");
        $this->info("Unchanged: {$unchanged}");
        $this->newLine();
        $this->info('Distribution by resolved country:');
        foreach ($tally as $country => $count) {
            $this->line("  {$country}: {$count}");
        }

        return self::SUCCESS;
    }
}
