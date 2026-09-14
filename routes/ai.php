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
});
