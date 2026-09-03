<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class PublishSummaryTool extends EditorTool
{
    protected const OPERATION = 'publish_summary';
}
