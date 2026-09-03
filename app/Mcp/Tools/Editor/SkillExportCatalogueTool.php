<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SkillExportCatalogueTool extends EditorTool
{
    protected const OPERATION = 'skill_export_catalogue';
}
