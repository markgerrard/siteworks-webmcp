<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListImageVersionsTool extends EditorTool
{
    protected const OPERATION = 'list_image_versions';
}
