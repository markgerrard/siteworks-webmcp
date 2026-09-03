<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ValidateDraftTool extends EditorTool
{
    protected const OPERATION = 'validate_draft';
}
