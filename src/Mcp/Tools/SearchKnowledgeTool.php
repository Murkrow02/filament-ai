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
 * Semantic search, exposed as a tool.
 *
 * The tool name comes from config so a host can call it something meaningful
 * to its own domain -- `search_books_knowledge` reads far better to a model
 * than a generic `search_knowledge`, and the name is most of what the model
 * uses to decide whether to reach for it.
 *
 * The search itself lives in `KnowledgeSearch`, shared with the panel agent.
 */
class SearchKnowledgeTool extends Tool
{
    public function name(): string
    {
        return (string) config('rag.mcp.tools.search.name', 'search_knowledge');
    }

    public function description(): string
    {
        return (string) __('rag::rag.mcp.search_description');
    }

    public function shouldRegister(): bool
    {
        return (bool) config('rag.mcp.tools.search.enabled', true);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $sources = app(SourceRegistry::class)->exposedKeys();

        return [
            'query' => $schema->string()
                ->description('A natural-language question or description of what to find. Full sentences retrieve better than keywords.')
                ->required(),

            'source' => $schema->string()
                ->enum($sources)
                ->description('Restrict the search to one knowledge source. Omit to search all of them.'),

            'document_ids' => $schema->array()
                ->items($schema->string())
                ->description('Restrict the search to specific documents, using the identifiers from the "documents" resource.'),

            'position_from' => $schema->integer()
                ->description('Only return passages at or after this position (for example a page number).'),

            'position_to' => $schema->integer()
                ->description('Only return passages at or before this position.'),

            'limit' => $schema->integer()
                ->description('How many passages to return. Defaults to the server configuration.'),

            'min_score' => $schema->number()
                ->description('Discard results below this cosine similarity, between 0 and 1.'),
        ];
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->description('The matching passages, most relevant first.'),
            'count' => $schema->integer()->description('How many passages were returned.'),
        ];
    }

    public function handle(Request $request, KnowledgeSearch $search): Response
    {
        $limit = $request->get('limit');
        $minScore = $request->get('min_score');
        $documentIds = $request->get('document_ids');

        // Always the exposed set, empty included: an empty set means the host
        // exposed nothing to MCP, and the search must return nothing rather
        // than quietly falling back to every source.
        $result = $search->search(
            query: (string) $request->get('query', ''),
            allowedSources: app(SourceRegistry::class)->exposedKeys(),
            source: $request->get('source') === null ? null : (string) $request->get('source'),
            documentIds: $documentIds === null ? null : (array) $documentIds,
            positionFrom: $this->toInt($request->get('position_from')),
            positionTo: $this->toInt($request->get('position_to')),
            limit: $this->toInt($limit),
            minScore: $minScore === null ? null : (float) $minScore,
        );

        return $result->isError ? Response::error($result->text) : Response::text($result->text);
    }

    private function toInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
