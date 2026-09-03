<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetPageStructureTool extends EditorTool
{
    protected const OPERATION = 'get_page_structure';
}
