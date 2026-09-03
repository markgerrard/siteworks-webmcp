<?php

namespace App\Services\Site\Editor;

use App\Models\Site\EditorOperationLog;
use DateTimeInterface;

final class EloquentEditorOperationLogRepository implements EditorOperationLogRepository
{
    /**
     * Deleted in bounded batches: the first run after a backlog would otherwise be one unbounded
     * DELETE holding a long transaction on a table with no created_at-leading index.
     */
    public function pruneOlderThan(DateTimeInterface $cutoff, int $chunk = 1000): int
    {
        $deleted = 0;

        do {
            $batch = EditorOperationLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += EditorOperationLog::query()->whereIn('id', $batch)->delete();
        } while ($batch->count() === $chunk);

        return $deleted;
    }
}
