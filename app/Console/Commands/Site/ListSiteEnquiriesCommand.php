<?php

namespace App\Console\Commands\Site;

use App\Models\SiteEnquiry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ListSiteEnquiriesCommand extends Command
{
    protected $signature = 'site-enquiries:list
        {site? : Only show enquiries for this site id}
        {--limit=25 : Maximum rows to show}';

    protected $description = 'List recent quote-form enquiries (newest first). The DB is the source of truth even when email notification is configured.';

    public function handle(): int
    {
        $enquiries = SiteEnquiry::query()
            ->when($this->argument('site'), fn ($q, $siteId) => $q->where('site_id', (int) $siteId))
            ->latest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($enquiries->isEmpty()) {
            $this->info('No enquiries.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Site', 'Name', 'Email', 'Page', 'Details', 'Received'],
            $enquiries->map(fn (SiteEnquiry $e) => [
                $e->id,
                $e->site_id,
                $e->name,
                $e->email,
                $e->page_type ?? '—',
                Str::limit(collect($e->payload ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | '), 60),
                $e->created_at->toDateTimeString(),
            ]),
        );

        return self::SUCCESS;
    }
}
