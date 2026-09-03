<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListThemeTokenPresetsTool extends EditorTool
{
    protected const OPERATION = 'list_theme_token_presets';
}
