<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Support\Text;

class SearchCommand extends Command
{
    protected $signature = 'rag:search
                            {query* : The search query}
                            {--source=* : Restrict to one or more sources}
                            {--document=* : Restrict to specific document identifiers}
                            {--from= : Lowest position (page) to consider}
                            {--to= : Highest position (page) to consider}
                            {--k= : How many results to return}
                            {--min-score= : Discard results below this cosine similarity}
                            {--full : Print the full chunk text instead of a snippet}';

    protected $description = 'Run retrieval only and print the ranked passages';

    public function handle(Retriever $retriever, SourceRegistry $sources): int
    {
        $query = implode(' ', (array) $this->argument('query'));

        $result = $retriever->retrieve($query, new RetrievalOptions(
            sourceKeys: $this->listOption('source'),
            externalIds: $this->listOption('document'),
            positionFrom: $this->intOption('from'),
            positionTo: $this->intOption('to'),
            topK: $this->intOption('k'),
            minScore: $this->option('min-score') === null ? null : (float) $this->option('min-score'),
        ));

        if ($result->isEmpty()) {
            $this->components->warn('No matching passages.');

            return self::SUCCESS;
        }

        if ($this->option('full')) {
            foreach ($result->chunks as $index => $chunk) {
                $this->newLine();
                $this->line('<fg=cyan>['.($index + 1).'] '.$this->label($sources, $chunk).'</> <fg=gray>('.number_format($chunk->score, 3).')</>');
                $this->line($chunk->content);
            }
        } else {
            $rows = [];

            foreach ($result->chunks as $index => $chunk) {
                $rows[] = [
                    $index + 1,
                    number_format($chunk->score, 3),
                    $this->label($sources, $chunk),
                    Text::snippet($chunk->content, 90),
                ];
            }

            $this->table(['#', 'Score', 'Source', 'Passage'], $rows);
        }

        $this->newLine();
        $this->components->twoColumnDetail('timings (ms)', json_encode($result->timings));
        $this->components->twoColumnDetail('candidates examined', (string) $result->candidatesExamined);

        return self::SUCCESS;
    }

    private function label(SourceRegistry $sources, ScoredChunk $chunk): string
    {
        $position = $sources->has($chunk->sourceKey)
            ? $sources->get($chunk->sourceKey)->positionLabel($chunk->positionStart, $chunk->positionEnd)
            : "{$chunk->positionStart}-{$chunk->positionEnd}";

        return ($chunk->documentTitle ?? $chunk->externalId).' - '.$position;
    }

    /**
     * @return array<int, string>|null
     */
    private function listOption(string $name): ?array
    {
        $values = array_values(array_filter((array) $this->option($name)));

        return $values === [] ? null : array_map(strval(...), $values);
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
