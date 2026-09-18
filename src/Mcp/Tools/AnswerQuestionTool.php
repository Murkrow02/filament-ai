<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\Citation;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * Full server-side RAG: retrieve, ground, answer, cite.
 *
 * Redundant when the caller is itself a capable model -- it will usually get
 * better results from the search tool and its own reasoning. It earns its place
 * for thin clients, and for keeping answers consistent with whatever the
 * application's own chat interface produces.
 */
class AnswerQuestionTool extends Tool
{
    public function name(): string
    {
        return (string) config('filament-ai.mcp.tools.answer.name', 'answer_question');
    }

    public function description(): string
    {
        return (string) __('filament-ai::messages.mcp.answer_description');
    }

    public function shouldRegister(): bool
    {
        return (bool) config('filament-ai.mcp.tools.answer.enabled', true);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('The question to answer from the knowledge base.')
                ->required(),

            'source' => $schema->string()
                ->enum(app(SourceRegistry::class)->exposedKeys())
                ->description('Restrict the answer to one knowledge source.'),

            'document_ids' => $schema->array()
                ->items($schema->string())
                ->description('Restrict the answer to specific documents.'),
        ];
    }

    public function handle(Request $request, Answerer $answerer): Response
    {
        $question = trim((string) $request->get('question', ''));

        if ($question === '') {
            return Response::error('The "question" argument is required.');
        }

        $source = $request->get('source');
        $documentIds = $request->get('document_ids');

        $result = $answerer->answer($question, new AnswerOptions(
            retrieval: new RetrievalOptions(
                sourceKeys: $source === null || $source === '' ? null : [(string) $source],
                externalIds: $documentIds === null || $documentIds === [] ? null : array_map(strval(...), (array) $documentIds),
            ),
            channel: QueryChannel::Mcp,
        ));

        if ($result->refused) {
            return Response::text($result->answer);
        }

        $lines = [$result->answer, '', 'Sources:'];

        foreach ($result->usedCitations() as $citation) {
            /** @var Citation $citation */
            $lines[] = "[#{$citation->marker}] {$citation->label} (document_id {$citation->chunk->externalId})";
        }

        return Response::text(implode("\n", $lines));
    }
}
