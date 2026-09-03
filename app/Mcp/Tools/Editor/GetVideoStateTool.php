<?php

namespace App\Mcp\Tools\Editor;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetVideoStateTool extends EditorTool
{
    protected const OPERATION = 'get_video_state';
}
