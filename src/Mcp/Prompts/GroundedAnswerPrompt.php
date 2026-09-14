<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * A reusable instruction block for clients that want to drive the retrieval
 * themselves: how to use the tools, and what "grounded" means here.
 */
class GroundedAnswerPrompt extends Prompt
{
    public function name(): string
    {
        return 'grounded_answer';
    }

    public function description(): string
    {
        return 'Instructions for answering a question strictly from this knowledge base, with citations.';
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'question',
                description: 'The question to answer from the knowledge base.',
                required: true,
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $question = (string) $request->get('question', '');
        $searchTool = (string) config('rag.mcp.tools.search.name', 'search_knowledge');
        $fetchTool = (string) config('rag.mcp.tools.fetch.name', 'fetch_document');

        return Response::text(<<<TEXT
            Answer the following question using only this knowledge base.

            Procedure:
              1. Read the "documents" resource if you do not already know which documents exist.
              2. Call {$searchTool} with the question phrased as a full sentence. Narrow by
                 source, document or position range when you know where to look.
              3. If a passage looks promising but incomplete, call {$fetchTool} to read around it.
              4. Answer only from what the passages say. Cite each claim as [#n], using the
                 markers from the search results. If the passages do not answer the question,
                 say so plainly rather than filling the gap from memory.
              5. Reproduce quoted text as it appears; this corpus may contain OCR errors.

            Question: {$question}
            TEXT);
    }
}
