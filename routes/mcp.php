<?php

use App\Mcp\Servers\EditorMcpServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

if (! class_exists(Mcp::class)) {
    return;
}

// Group the call itself: Mcp::web() creates two unnamed GET/DELETE 405
// siblings, while chaining middleware on its return only decorates POST.
// throttle:mcp applies here
Route::middleware(['throttle:mcp', 'require.acting_user'])
    ->group(function (): void {
        Mcp::web('/mcp/editor', EditorMcpServer::class)->name('mcp.editor');
    });
