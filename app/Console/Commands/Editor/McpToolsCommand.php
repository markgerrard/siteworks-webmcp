<?php

namespace App\Console\Commands\Editor;

use App\Models\Site;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ToolExposure;
use Illuminate\Console\Command;

final class McpToolsCommand extends Command
{
    protected $signature = 'editor:mcp-tools
        {site? : Site id whose effective exposure set to print}';

    protected $description = 'List registered editor MCP tool names, or the effective exposure set for a site.';

    public function handle(OperationRegistry $registry, ToolExposure $exposure): int
    {
        $siteId = $this->argument('site');

        if ($siteId === null) {
            collect($registry->all())
                ->keys()
                ->map(fn (string $operation): string => 'siteworks.'.$operation)
                ->sort()
                ->each(fn (string $name) => $this->line($name));

            return self::SUCCESS;
        }

        $siteId = (string) $siteId;

        if (! ctype_digit($siteId)) {
            $this->error("Site id [{$siteId}] is not a positive integer.");

            return self::FAILURE;
        }

        $site = Site::query()->find((int) $siteId);

        if ($site === null) {
            $this->error("Site [{$siteId}] not found.");

            return self::FAILURE;
        }

        // "What does the client sandbox actually register" is answerable from the command line
        // (spec § 8): the set name plus the registrable tools it admits.
        $exposed = $exposure->setFor($site);

        $this->line('exposure set: '.$exposure->nameFor($site));
        collect($registry->all())
            ->keys()
            ->filter(fn (string $operation): bool => in_array($operation, $exposed, true))
            ->map(fn (string $operation): string => 'siteworks.'.$operation)
            ->sort()
            ->each(fn (string $name) => $this->line($name));

        return self::SUCCESS;
    }
}
