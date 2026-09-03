<?php

namespace App\Console\Commands\Site;

use App\Models\Site;
use App\Services\Site\SiteClone\SiteCloneOptions;
use App\Services\Site\SiteCloneService;
use Illuminate\Console\Command;

class SiteCloneCommand extends Command
{
    protected $signature = 'site:clone
        {site : Source site id, preview_domain or custom_domain}
        {--preview-domain= : Slug for the clone (default: <source>-clone, -clone-2, …)}
        {--client-id= : Destination clients.id to attach; omitted leaves client_id null}
        {--skip-spaces : Skip Spaces asset mirroring (DB rows only — images will 404)}';

    protected $description = 'Duplicate a site (DB rows + Spaces assets) inside the current environment — e.g. to rework a copy without touching the original.';

    public function handle(SiteCloneService $cloner): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $token = (string) $this->argument('site');
        $site = $this->findSite($token);
        if (! $site) {
            $this->error("No site matches '{$token}'.");

            return self::FAILURE;
        }

        $previewRoot = (string) config('services.storage.preview_root');
        $requestedDomain = (string) ($this->option('preview-domain') ?? '');

        return $cloner->run($this, new SiteCloneOptions(
            sourceConnection: (string) config('database.default'),
            sourcePrefix: $previewRoot,
            destPrefix: $previewRoot,
            skipSpaces: (bool) $this->option('skip-spaces'),
            previewDomain: $requestedDomain !== '' ? $requestedDomain : null,
            preservePreviewDomain: $requestedDomain === '',
            sourceLabel: 'this environment',
            legacyDevOutput: app()->environment('local'),
            destClientId: $this->option('client-id') !== null ? (int) $this->option('client-id') : null,
        ), $site->id);
    }

    private function findSite(string $token): ?Site
    {
        if (ctype_digit($token)) {
            return Site::query()->find((int) $token);
        }

        return Site::query()
            ->where(function ($query) use ($token): void {
                $query->where('custom_domain', $token)
                    ->orWhere('preview_domain', $token);
            })
            ->first();
    }
}
