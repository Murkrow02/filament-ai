<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RAG MCP routes
|--------------------------------------------------------------------------
|
| The package registers its MCP server automatically from the service
| provider, so this file is optional. Publish it with
|
|     php artisan vendor:publish --tag=filament-ai-ai-routes
|
| only when you want the registration in your own routes file -- for example
| to add middleware or mount it under a different path. If you do, set
| FILAMENT_AI_MCP_WEB_ENABLED=false and FILAMENT_AI_MCP_LOCAL_ENABLED=false so the server is
| not registered twice.
|
*/

use Laravel\Mcp\Facades\Mcp;
use Murkrow\FilamentAi\Mcp\KnowledgeServer;

Mcp::web('mcp/knowledge', KnowledgeServer::class)
    ->middleware(['auth:sanctum']);

Mcp::local('knowledge', KnowledgeServer::class);
