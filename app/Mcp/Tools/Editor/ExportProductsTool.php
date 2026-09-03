<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ExportProductsTool extends EditorTool
{
    protected const OPERATION = 'export_products';
}
