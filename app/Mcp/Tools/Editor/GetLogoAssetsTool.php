<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetLogoAssetsTool extends EditorTool
{
    protected const OPERATION = 'get_logo_assets';
}
