<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class InspectDraftTool extends EditorTool
{
    protected const OPERATION = 'inspect_draft';
}
