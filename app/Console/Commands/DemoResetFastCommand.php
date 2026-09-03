<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSnapshot;
use Illuminate\Console\Command;

class DemoResetFastCommand extends Command
{
    protected $signature = 'demo:reset-fast {action=reset : snapshot|reset|assert}';

    protected $description = 'Between-takes demo reset from the in-container snapshot (demo mode only).';

    public function handle(DemoSnapshot $snapshot): int
    {
        if (! (bool) config('demo.enabled', false)) {
            $this->error('demo:reset-fast runs only with DEMO_MODE=true.');

            return self::FAILURE;
        }
        $out = match ($this->argument('action')) {
            'snapshot' => (function () use ($snapshot) { $snapshot->snapshot(); return ['snapshot' => true] + $snapshot->state(); })(),
            'reset' => $snapshot->reset(),
            'assert' => $snapshot->state(),
            default => null,
        };
        if ($out === null) {
            $this->error('action must be snapshot, reset or assert');

            return self::FAILURE;
        }
        $this->line(json_encode($out));

        return self::SUCCESS;
    }
}
