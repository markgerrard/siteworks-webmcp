<?php

namespace App\Console\Commands\Editor;

use App\Mcp\Servers\EditorMcpServer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Transport\FakeTransporter;

final class McpDoctorCommand extends Command
{
    protected $signature = 'editor:mcp-doctor {--user= : Run as this user id when listing flag-eligible tools}';

    protected $description = 'Report whether the editor MCP server is registered and list flag-eligible tools for a given user.';

    public function handle(): int
    {
        $user = $this->actingUser();

        if ($user instanceof User) {
            Auth::setUser($user);
        }

        $registered = class_exists(Mcp::class)
            && class_exists(EditorMcpServer::class)
            && Route::has('mcp.editor');

        $this->line('registered: '.($registered ? 'true' : 'false'));
        $this->line('acting_user: '.($user instanceof User ? 'true' : 'false'));

        if (! $registered) {
            $this->line('tool_count: 0');

            return self::FAILURE;
        }

        $server = $this->laravel->make(EditorMcpServer::class, [
            'transport' => new FakeTransporter,
        ]);

        $names = collect($server->tools())
            ->map(fn (string $class): string => $this->laravel->make($class)->name())
            ->sort()
            ->values();

        $this->line('tool_count: '.$names->count());

        foreach ($names as $name) {
            $this->line($name);
        }

        return self::SUCCESS;
    }

    private function actingUser(): ?User
    {
        $userId = $this->option('user');

        if ($userId !== null && $userId !== false && $userId !== '') {
            $user = User::query()->find((int) $userId);

            return $user instanceof User ? $user : null;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
