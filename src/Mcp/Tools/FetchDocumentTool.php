<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Murkrow\FilamentAi\Knowledge\KnowledgeSearch;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * Reads a contiguous span of an indexed document.
 *
 * Search returns isolated passages, which is the right unit for ranking and
 * the wrong one for reading. This is the follow-up call: having found page 47,
 * a client can pull 45-50 and see the argument in context.
 *
 * The read itself lives in `KnowledgeSearch`, shared with the panel agent.
 */
class FetchDocumentTool extends Tool
{
    public function name(): string
    {
        return (string) config('rag.mcp.tools.fetch.name', 'fetch_document');
    }

    public function description(): string
    {
        return (string) __('rag::rag.mcp.fetch_description');
    }

    public function shouldRegister(): bool
    {
        return (bool) config('rag.mcp.tools.fetch.enabled', true);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()
                ->description('The document identifier, as returned by the search tool or the "documents" resource.')
                ->required(),

            'source' => $schema->string()
                ->enum(app(SourceRegistry::class)->exposedKeys())
                ->description('The source the document belongs to. Only needed when two sources share an identifier.'),

            'position_from' => $schema->integer()
                ->description('First position to read, for example a page number. Defaults to the start of the document.'),

            'position_to' => $schema->integer()
                ->description('Last position to read. Defaults to a short span after position_from.'),
        ];
    }

    public function handle(Request $request, KnowledgeSearch $search): Response
    {
        $from = $request->get('position_from');
        $to = $request->get('position_to');
        $source = $request->get('source');

        // Scoped to what the host exposed, always: naming a hidden source
        // must not reach it, and an empty allow-list must reach nothing.
        $result = $search->fetch(
            externalId: (string) $request->get('document_id', ''),
            allowedSources: app(SourceRegistry::class)->exposedKeys(),
            source: $source === null || $source === '' ? null : (string) $source,
            positionFrom: $from === null ? null : (int) $from,
            positionTo: $to === null ? null : (int) $to,
        );

        return $result->isError ? Response::error($result->text) : Response::text($result->text);
    }
}
