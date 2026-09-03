<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetSiteContextTool extends EditorTool
{
    protected const OPERATION = 'get_site_context';
}
