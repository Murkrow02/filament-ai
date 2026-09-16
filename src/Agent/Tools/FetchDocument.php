<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Knowledge\KnowledgeSearch;

/**
 * Lets the panel agent read a contiguous span of an indexed document.
 */
final class FetchDocument implements Tool
{
    /**
     * @param  list<string>  $sources  the knowledge sources this agent may read
     */
    public function __construct(private readonly array $sources) {}

    public function name(): string
    {
        return 'fetch_document';
    }

    public function description(): string
    {
        return 'Read a contiguous span of one indexed document, by the document_id returned from search_knowledge. '
            .'Use it to read around a promising passage before answering.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()
                ->description('The document identifier returned by search_knowledge.')
                ->required(),
            'position_from' => $schema->integer()
                ->description('First position to read, for example a page number. Defaults to the start of the document.'),
            'position_to' => $schema->integer()
                ->description('Last position to read.'),
        ];
    }

    public function handle(Request $request): string
    {
        $arguments = $request->all();

        return app(KnowledgeSearch::class)->fetch(
            externalId: (string) ($arguments['document_id'] ?? ''),
            allowedSources: $this->sources,
            positionFrom: isset($arguments['position_from']) ? (int) $arguments['position_from'] : null,
            positionTo: isset($arguments['position_to']) ? (int) $arguments['position_to'] : null,
        )->toToolOutput();
    }
}
