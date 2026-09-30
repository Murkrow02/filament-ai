<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Reranking;

use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Contracts\Reranker;
use RuntimeException;

/**
 * Listwise reranking with the configured generation model: one call grades
 * every candidate. Needs nothing installed, costs one request per search.
 */
final class LlmReranker implements Reranker
{
    public function __construct(private readonly LanguageModel $llm) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function score(string $question, array $passages): array
    {
        if ($passages === []) {
            return [];
        }

        // A safety cap only: the retriever already sends each passage as the
        // excerpt around the question's words (rerank.max_chars).
        $maxChars = (int) config('filament-ai.retrieval.rerank.llm_max_chars', 1600);
        $blocks = [];

        foreach (array_values($passages) as $index => $passage) {
            $blocks[] = '['.($index + 1).'] '.preg_replace('/\s+/u', ' ', mb_substr($passage, 0, $maxChars));
        }

        $system = 'You grade search results. For each numbered passage, rate from 0 to 10 how well it answers '
            .'the question, including when it uses different words (names instead of roles, archaic or dialect '
            .'terms, a paraphrase or a riddle-like question). 10 = contains the answer; 6-8 = the same story, '
            .'person, place or event, where the answer is likely nearby; 2-4 = the same subject in general; '
            .'0 = unrelated. Grade relative to each other: the best passages must not all get the same score. '
            .'Reply with JSON only: {"scores": [n1, n2, ...]} with exactly one integer per passage, in order.';

        $model = config('filament-ai.retrieval.rerank.llm_model');

        $response = $this->llm->generate(
            $system,
            "Question: {$question}\n\n".implode("\n\n", $blocks),
            blank($model) ? null : (string) $model,
            0.0,
            20 + 4 * count($passages),
        );

        if (preg_match('/\{.*\}/s', $response['text'], $match) !== 1) {
            throw new RuntimeException('The reranking model did not return JSON.');
        }

        $scores = json_decode($match[0], true)['scores'] ?? null;

        if (! is_array($scores) || count($scores) !== count($passages)) {
            throw new RuntimeException('The reranking model returned the wrong number of scores.');
        }

        return array_map(static fn (mixed $s): float => max(0.0, min(1.0, (float) $s / 10)), array_values($scores));
    }
}
