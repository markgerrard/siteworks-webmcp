<?php

namespace App\Console\Commands\Editor;

use App\Services\Site\Editor\EditorOperationLogRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PruneOperationLogCommand extends Command
{
    protected $signature = 'editor:prune-operation-log';

    protected $description = 'Delete editor_operation_log rows older than the configured retention.';

    public function handle(EditorOperationLogRepository $logs): int
    {
        // A missing / empty / 0 / negative retention means "keep everything", NEVER "delete everything":
        // this table is the audit trail for every denied agent call, and this command runs daily unattended.
        // Same guard as App\Console\Commands\Site\PrunePageRevisions.
        $days = (int) config('editor.operation_log_retention_days');

        if ($days <= 0) {
            // Scheduler stdout goes nowhere, so a mangled env would silently grow this table forever.
            // Disabled is a valid configured state (exit SUCCESS), but it is worth one line where ops look.
            Log::channel(config('logging.auth_channel', 'stack'))->warning('editor_operation_log_prune_disabled', [
                'event' => 'editor_operation_log_prune_disabled',
                'configured' => config('editor.operation_log_retention_days'),
            ]);

            $this->info('editor.operation_log_retention_days is not a positive number — pruning is disabled, nothing deleted.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        try {
            $deleted = $logs->pruneOlderThan($cutoff);
        } catch (Throwable $e) {
            Log::channel(config('logging.auth_channel', 'stack'))->error('editor_operation_log_prune_failed', [
                'event' => 'editor_operation_log_prune_failed',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pruned {$deleted} editor_operation_log rows.");

        return self::SUCCESS;
    }
}
