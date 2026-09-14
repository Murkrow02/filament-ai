<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Knowledge\KnowledgeSearch;

/**
 * Lets the panel agent search the indexed knowledge base.
 */
final class SearchKnowledge implements Tool
{
    /**
     * @param  list<string>  $sources  the knowledge sources this agent may read
     */
    public function __construct(private readonly array $sources) {}

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the indexed knowledge base (documents, manuals, archives) by meaning. '
            .'Returns numbered passages with the document and position they came from. '
            .'Use it for questions about the content of documents, not for application records. '
            .'When an answer relies on a passage, cite its marker, e.g. [#1].';
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [
            'query' => $schema->string()
                ->description('A natural-language question or description of what to find. Full sentences retrieve better than keywords.')
                ->required(),
            'document_ids' => $schema->array()
                ->items($schema->string())
                ->description('Restrict the search to specific documents, by the document_id shown in earlier results.'),
            'position_from' => $schema->integer()
                ->description('Only return passages at or after this position, for example a page number.'),
            'position_to' => $schema->integer()
                ->description('Only return passages at or before this position.'),
            'limit' => $schema->integer()
                ->description('How many passages to return, at most 20.'),
        ];

        if ($this->sources !== []) {
            $fields['source'] = $schema->string()
                ->enum($this->sources)
                ->description('Restrict the search to one knowledge source. Omit to search all of them.');
        }

        return $fields;
    }

    public function handle(Request $request): string
    {
        $arguments = $request->all();

        return app(KnowledgeSearch::class)->search(
            query: (string) ($arguments['query'] ?? ''),
            allowedSources: $this->sources,
            source: isset($arguments['source']) ? (string) $arguments['source'] : null,
            documentIds: isset($arguments['document_ids']) ? (array) $arguments['document_ids'] : null,
            positionFrom: isset($arguments['position_from']) ? (int) $arguments['position_from'] : null,
            positionTo: isset($arguments['position_to']) ? (int) $arguments['position_to'] : null,
            limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
        )->toToolOutput();
    }
}
