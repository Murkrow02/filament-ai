<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Version;
use Murkrow\FilamentAi\Mcp\Prompts\GroundedAnswerPrompt;
use Murkrow\FilamentAi\Mcp\Resources\DocumentsResource;
use Murkrow\FilamentAi\Mcp\Tools\AnswerQuestionTool;
use Murkrow\FilamentAi\Mcp\Tools\FetchDocumentTool;
use Murkrow\FilamentAi\Mcp\Tools\SearchKnowledgeTool;

/**
 * Exposes the knowledge base to MCP clients.
 *
 * The resource matters as much as the tools: a client that can list the
 * indexed documents first can then scope its searches to the right one instead
 * of guessing identifiers, which is the difference between a useful tool and
 * one the model gives up on.
 */
#[Version('1.0.0')]
class KnowledgeServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SearchKnowledgeTool::class,
        FetchDocumentTool::class,
        AnswerQuestionTool::class,
    ];

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        DocumentsResource::class,
    ];

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        GroundedAnswerPrompt::class,
    ];

    public function name(): string
    {
        return (string) config('filament-ai.mcp.server.name', 'knowledge');
    }

    public function instructions(): string
    {
        $configured = config('filament-ai.mcp.server.instructions');

        return $configured === null || $configured === ''
            ? (string) __('filament-ai::messages.mcp.instructions')
            : (string) $configured;
    }
}
