<?php

namespace App\Services\Site\Editor;

use RuntimeException;

final class OperationFailed extends RuntimeException
{
    public function __construct(public readonly OperationResult $result)
    {
        parent::__construct($result->error['message'] ?? 'operation failed');
    }
}
