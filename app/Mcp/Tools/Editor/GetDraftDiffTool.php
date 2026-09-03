<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetDraftDiffTool extends EditorTool
{
    protected const OPERATION = 'get_draft_diff';
}
