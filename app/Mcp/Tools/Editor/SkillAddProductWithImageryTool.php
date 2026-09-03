<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SkillAddProductWithImageryTool extends EditorTool
{
    protected const OPERATION = 'skill_add_product_with_imagery';
}
