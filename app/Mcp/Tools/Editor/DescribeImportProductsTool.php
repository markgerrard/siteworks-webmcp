<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class DescribeImportProductsTool extends EditorTool
{
    protected const OPERATION = 'describe_import_products';
}
