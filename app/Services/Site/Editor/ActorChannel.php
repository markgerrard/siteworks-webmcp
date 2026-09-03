<?php

namespace App\Services\Site\Editor;

enum ActorChannel: string
{
    case Ui = 'ui';
    case Webmcp = 'webmcp';
    case Mcp = 'mcp';

    public function isAgent(): bool
    {
        return $this !== self::Ui;
    }
}
