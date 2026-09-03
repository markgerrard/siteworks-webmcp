<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetBrandContextTool extends EditorTool
{
    protected const OPERATION = 'get_brand_context';
}
