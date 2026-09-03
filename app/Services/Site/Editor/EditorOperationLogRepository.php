<?php

namespace App\Services\Site\Editor;

use DateTimeInterface;

interface EditorOperationLogRepository
{
    public function pruneOlderThan(DateTimeInterface $cutoff, int $chunk = 1000): int;
}
