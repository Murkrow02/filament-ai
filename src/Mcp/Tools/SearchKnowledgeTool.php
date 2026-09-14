<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * Semantic search, exposed as a tool.
 *
 * The tool name comes from config so a host can call it something meaningful
 * to its own domain -- `search_books_knowledge` reads far better to a model
 * than a generic `search_knowledge`, and the name is most of what the model
 * uses to decide whether to reach for it.
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

    public function handle(Request $request, Retriever $retriever): Response
    {
        $query = trim((string) $request->get('query', ''));

        if ($query === '') {
            return Response::error('The "query" argument is required.');
        }

        $limit = $request->get('limit');

        $options = new RetrievalOptions(
            sourceKeys: $this->sourceKeys($request->get('source')),
            externalIds: $this->toList($request->get('document_ids')),
            positionFrom: $this->toInt($request->get('position_from')),
            positionTo: $this->toInt($request->get('position_to')),
            topK: $limit === null ? null : max(1, min(20, (int) $limit)),
            minScore: $request->get('min_score') === null ? null : (float) $request->get('min_score'),
        );

        $result = $retriever->retrieve($query, $options);

        if ($result->isEmpty()) {
            return Response::text('No passage in the knowledge base matches that query.');
        }

        $blocks = [];
        $marker = 1;

        foreach ($result->chunks as $chunk) {
            $blocks[] = $this->render($marker++, $chunk);
        }

        return Response::text(implode("\n\n", $blocks));
    }

    private function render(int $marker, ScoredChunk $chunk): string
    {
        $sources = app(SourceRegistry::class);

        $position = $sources->has($chunk->sourceKey)
            ? $sources->get($chunk->sourceKey)->positionLabel($chunk->positionStart, $chunk->positionEnd)
            : "{$chunk->positionStart}-{$chunk->positionEnd}";

        $title = $chunk->documentTitle ?? $chunk->externalId;
        $score = number_format($chunk->score, 2);

        $header = "[#{$marker}] {$title} - {$position} (score {$score}, document_id {$chunk->externalId})";

        return $header."\n".$chunk->content;
    }

    /**
     * Always returns the exposed set, empty included: an empty set means the
     * host exposed nothing to MCP, and the search must return nothing rather
     * than quietly falling back to every source.
     *
     * @return array<int, string>
     */
    private function sourceKeys(mixed $source): array
    {
        $exposed = app(SourceRegistry::class)->exposedKeys();

        if ($source === null || $source === '') {
            return $exposed;
        }

        // A source the host did not expose stays inaccessible even if named.
        return array_values(array_intersect($exposed, [(string) $source]));
    }

    /**
     * @return array<int, string>|null
     */
    private function toList(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        return array_map(strval(...), (array) $value);
    }

    private function toInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
