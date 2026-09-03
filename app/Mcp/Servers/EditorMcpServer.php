<?php

namespace App\Mcp\Servers;

use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ToolExposure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;
use LogicException;

final class EditorMcpServer extends Server
{
    protected string $name = 'Siteworks Editor Tools';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read and edit Siteworks site drafts. These tools never publish.';

    public function __construct(
        Transport $transport,
        private readonly OperationRegistry $registry,
        private readonly AgentToolsGate $gate,
        private readonly ToolExposure $exposure,
    ) {
        parent::__construct($transport);

        $this->tools = $this->tools();
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public function tools(): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $this->gate->enabledFor($user, ActorChannel::Mcp)) {
            return [];
        }

        // No site exists at construction (site_id arrives as an argument to each tool), so Front 3
        // registers the INTERNAL set — correct rather than a compromise: /mcp/editor requires an
        // authenticated portal session, and the client sandbox is a separate Front 2 browser tenant.
        // Tenant scoping binds at EXECUTION (EditorOperations::run), not at registration.
        $exposed = $this->exposure->named('internal');

        return collect($this->registry->all())
            ->filter(fn ($operation): bool => $this->gate->enabledForUserAndOperation(
                $user,
                ActorChannel::Mcp,
                $operation,
            ))
            ->keys()
            ->filter(fn (string $operation): bool => in_array($operation, $exposed, true))
            ->map(function (string $operation): string {
                $tool = 'App\\Mcp\\Tools\\Editor\\'.Str::studly($operation).'Tool';

                if (! is_subclass_of($tool, Tool::class)) {
                    throw new LogicException("Editor MCP tool [{$tool}] is missing for operation [{$operation}].");
                }

                return $tool;
            })
            ->values()
            ->all();
    }
}
