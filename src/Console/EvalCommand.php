<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\ScoredChunk;

/**
 * Measures retrieval against a set of questions with known answers.
 *
 * The file is JSON: a list of
 *
 *     {"question": "...", "category": "paraphrase",
 *      "expected": [{"document_id": "1115", "source": "books", "pages": [7, 8]}]}
 *
 * where `expected` lists every passage that answers the question (a hit on
 * any of them counts), `source` and `page` (or a `pages` range) are optional, and `document_id` is
 * the host identifier shown in search results. Retrieval runs with the current
 * configuration, adjusted by the options, so two runs compare two setups.
 */
class EvalCommand extends Command
{
    protected $signature = 'ai:eval
                            {file : JSON file of questions with their expected passages}
                            {--k=20 : How many passages to retrieve per question}
                            {--hybrid= : Lexical driver for this run (tsvector, scout, none)}
                            {--expand= : Query expansion for this run (1 or 0)}
                            {--rerank= : Reranker for this run (ollama, llm, none)}
                            {--category=* : Only questions of these categories}
                            {--only=* : Only the questions with these ids}
                            {--details : Print the rank of every question}
                            {--json= : Also write per-question results to this file}';

    protected $description = 'Measure retrieval recall and MRR on a set of questions with known answers';

    public function handle(Retriever $retriever): int
    {
        $file = (string) $this->argument('file');
        $cases = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($cases)) {
            $this->components->error("Cannot read a JSON list of questions from [{$file}].");

            return self::FAILURE;
        }

        $k = max(1, (int) $this->option('k'));
        $categories = array_filter((array) $this->option('category'));
        $only = array_filter((array) $this->option('only'));
        $results = [];

        foreach (array_values($cases) as $index => $case) {
            $id = (string) ($case['id'] ?? $index + 1);
            $category = (string) ($case['category'] ?? 'general');

            if (($categories !== [] && ! in_array($category, $categories, true)) || ($only !== [] && ! in_array($id, $only, true))) {
                continue;
            }

            $start = hrtime(true);

            $result = $retriever->retrieve((string) $case['question'], new RetrievalOptions(
                sourceKeys: isset($case['sources']) ? (array) $case['sources'] : null,
                topK: $k,
                mmr: false,
                hybridDriver: $this->option('hybrid') === null ? null : (string) $this->option('hybrid'),
                expand: $this->option('expand') === null ? null : (bool) (int) $this->option('expand'),
                rerankDriver: $this->option('rerank') === null ? null : (string) $this->option('rerank'),
            ));

            $rank = $this->firstRelevantRank($result->chunks->all(), (array) ($case['expected'] ?? []));

            $results[] = [
                'id' => $id,
                'category' => $category,
                'question' => (string) $case['question'],
                'rank' => $rank,
                'ms' => (int) round((hrtime(true) - $start) / 1_000_000),
                'top' => $result->chunks->take(3)->map(
                    static fn (ScoredChunk $c): string => $c->externalId.'@'.$c->positionStart.'-'.$c->positionEnd,
                )->implode(' '),
            ];

            if ($this->option('details')) {
                $this->line(sprintf('%-6s %-12s %-6s %5dms  %s', $id, $category, $rank ?? '-', end($results)['ms'], mb_strimwidth((string) $case['question'], 0, 80, '…')));
            }
        }

        if ($results === []) {
            $this->components->warn('No question matched.');

            return self::SUCCESS;
        }

        $this->summary($results, $k);

        if (($path = $this->option('json')) !== null) {
            file_put_contents((string) $path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<ScoredChunk>  $chunks
     * @param  array<int, array<string, mixed>>  $expected
     */
    private function firstRelevantRank(array $chunks, array $expected): ?int
    {
        foreach ($chunks as $index => $chunk) {
            foreach ($expected as $target) {
                if ((string) ($target['document_id'] ?? '') !== $chunk->externalId) {
                    continue;
                }

                if (isset($target['source']) && $target['source'] !== $chunk->sourceKey) {
                    continue;
                }

                if (isset($target['page']) && ((int) $target['page'] < $chunk->positionStart || (int) $target['page'] > $chunk->positionEnd)) {
                    continue;
                }

                // A page range counts on any overlap: the answer may sit on
                // either page, and chunks do not start where pages do.
                if (isset($target['pages']) && ((int) $target['pages'][0] > $chunk->positionEnd || (int) $target['pages'][1] < $chunk->positionStart)) {
                    continue;
                }

                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @param  list<array{category: string, rank: int|null, ms: int}>  $results
     */
    private function summary(array $results, int $k): void
    {
        $groups = ['all' => $results];

        foreach ($results as $result) {
            $groups[$result['category']][] = $result;
        }

        $cutoffs = array_values(array_unique(array_filter([1, 5, 10, $k], static fn (int $n): bool => $n <= $k)));
        $rows = [];

        foreach ($groups as $name => $group) {
            $row = [$name, count($group)];

            foreach ($cutoffs as $cutoff) {
                $hits = count(array_filter($group, static fn (array $r): bool => $r['rank'] !== null && $r['rank'] <= $cutoff));
                $row[] = number_format($hits / count($group), 2);
            }

            $row[] = number_format(array_sum(array_map(static fn (array $r): float => $r['rank'] === null ? 0.0 : 1 / $r['rank'], $group)) / count($group), 3);
            $row[] = (int) round(array_sum(array_column($group, 'ms')) / count($group));
            $rows[] = $row;
        }

        $this->table(
            ['category', 'n', ...array_map(static fn (int $c): string => "R@{$c}", $cutoffs), 'MRR', 'avg ms'],
            $rows,
        );
    }
}
