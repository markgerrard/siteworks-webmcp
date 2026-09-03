<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetEffectiveHeroStateTool extends EditorTool
{
    protected const OPERATION = 'get_effective_hero_state';
}
