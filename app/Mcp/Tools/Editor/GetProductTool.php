<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetProductTool extends EditorTool
{
    protected const OPERATION = 'get_product';
}
