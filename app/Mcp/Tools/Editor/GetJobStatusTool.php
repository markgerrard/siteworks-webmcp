<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetJobStatusTool extends EditorTool
{
    protected const OPERATION = 'get_job_status';
}
