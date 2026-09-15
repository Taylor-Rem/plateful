<?php

use App\Mcp\Servers\PlatformServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
 * Remote MCP server on the primary host, signed by an API key (`pfk_…`).
 * The tools wrap the same queries and services as /api/v1/operator/*, so
 * whatever a key may do over REST it may do here, and nothing more.
 * Connect with: claude mcp add --transport http plateful https://plateful.fyi/mcp/platform
 *   --header "Authorization: Bearer pfk_live_…"
 */
Route::domain(config('platform.primary_domain'))->group(function () {
    Mcp::web('/mcp/platform', PlatformServer::class)
        ->middleware(['auth:api-key', 'throttle:api-operator'])
        ->name('mcp.platform');

    // The same server for clients that cannot send an Authorization header
    // (claude.ai and ChatGPT custom connectors take a bare URL): the key
    // rides in the path and the `api-key` guard reads it from there. The
    // URL is therefore a secret; the AI assistant page says so and revokes.
    Route::pattern('connectKey', 'pfk_[A-Za-z0-9_]+');
    Mcp::web('/mcp/platform/{connectKey}', PlatformServer::class)
        ->middleware(['auth:api-key', 'throttle:api-operator'])
        ->name('mcp.platform.connect');
});
