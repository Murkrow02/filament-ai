<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Support\Tables;

class StatusCommand extends Command
{
    protected $signature = 'rag:status
                            {--run= : Show a single run by uuid}
                            {--watch : Refresh every two seconds}';

    protected $description = 'Show corpus coverage, recent runs and accumulated cost';

    public function handle(SourceRegistry $sources): int
    {
        do {
            if ($this->option('watch')) {
                $this->output->write("\033[2J\033[H");
            }

            $runUuid = $this->option('run');

            if ($runUuid !== null) {
                $this->showRun((string) $runUuid);
            } else {
                $this->showOverview($sources);
            }

            if ($this->option('watch')) {
                usleep(2_000_000);
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }

    private function showOverview(SourceRegistry $sources): void
    {
        $model = (string) config('rag.embeddings.model');
        $dimensions = (int) config('rag.embeddings.dimensions');

        $documents = Document::query()->count();
        $chunks = Chunk::query()->count();
        $embedded = Chunk::query()->whereNotNull('embedded_at')->count();

        // Vectors produced by a different model are unusable for retrieval and
        // are the single most common cause of "search stopped working".
        $mismatched = Chunk::query()
            ->whereNotNull('embedded_at')
            ->where(function ($query) use ($model, $dimensions): void {
                $query->where('embedding_model', '!=', $model)
                    ->orWhere('embedding_dimensions', '!=', $dimensions);
            })
            ->count();

        $this->components->twoColumnDetail('<fg=cyan>Corpus</>', '');
        $this->components->twoColumnDetail('documents', number_format($documents));
        $this->components->twoColumnDetail('chunks', number_format($chunks));
        $this->components->twoColumnDetail(
            'embedded',
            number_format($embedded).($chunks > 0 ? ' <fg=gray>('.round($embedded / $chunks * 100).'%)</>' : ''),
        );

        if ($chunks - $embedded > 0) {
            $this->components->twoColumnDetail('awaiting embedding', '<fg=yellow>'.number_format($chunks - $embedded).'</>');
        }

        if ($mismatched > 0) {
            $this->components->twoColumnDetail(
                'stale vectors',
                '<fg=red>'.number_format($mismatched).' from another model - run rag:ingest --mode=embeddings_only</>',
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Per source</>', '');

        $rows = [];

        foreach ($sources->keys() as $key) {
            $sourceDocuments = Document::query()->where('source_key', $key)->count();
            $sourceChunks = Chunk::query()->where('source_key', $key)->count();
            $sourceEmbedded = Chunk::query()->where('source_key', $key)->whereNotNull('embedded_at')->count();

            $rows[] = [
                $key,
                number_format($sourceDocuments),
                number_format($sourceChunks),
                $sourceChunks > 0 ? round($sourceEmbedded / $sourceChunks * 100).'%' : '-',
            ];
        }

        if ($rows !== []) {
            $this->table(['Source', 'Documents', 'Chunks', 'Coverage'], $rows);
        }

        $runs = IngestionRun::query()->latest('id')->limit(5)->get();

        if ($runs->isNotEmpty()) {
            $this->components->twoColumnDetail('<fg=cyan>Recent runs</>', '');

            $this->table(
                ['Run', 'Source', 'Status', 'Progress', 'Chunks', 'Cost'],
                $runs->map(static fn (IngestionRun $run): array => [
                    substr($run->uuid, 0, 8),
                    $run->source_key,
                    $run->status->label(),
                    $run->progressPercent().'%',
                    $run->chunks_embedded.'/'.$run->chunks_total,
                    CostCalculator::format($run->cost_micros, 4),
                ])->all(),
            );
        }

        $totalCost = (int) IngestionRun::query()->sum('cost_micros')
            + (int) QueryLog::query()->sum('cost_micros');

        $this->components->twoColumnDetail('total spend to date', CostCalculator::format($totalCost, 4));
        $this->components->twoColumnDetail('queries logged', number_format(QueryLog::query()->count()));
        $this->components->twoColumnDetail('vector driver', (string) config('rag.vector.driver'));
        $this->components->twoColumnDetail('embedding model', $model.' <fg=gray>('.$dimensions.'d)</>');

        $this->warnAboutIndex();
    }

    private function showRun(string $uuid): void
    {
        $run = IngestionRun::query()->where('uuid', 'like', $uuid.'%')->first();

        if ($run === null) {
            $this->components->error("No run matching [{$uuid}].");

            return;
        }

        $this->components->twoColumnDetail('run', $run->uuid);
        $this->components->twoColumnDetail('source', $run->source_key);
        $this->components->twoColumnDetail('mode', $run->mode->label());
        $this->components->twoColumnDetail('status', $run->status->label());
        $this->components->twoColumnDetail('progress', $run->progressPercent().'%');
        $this->components->twoColumnDetail('documents', $run->documents_done.' done / '.$run->documents_skipped.' skipped / '.$run->documents_failed.' failed of '.$run->documents_total);
        $this->components->twoColumnDetail('chunks', $run->chunks_created.' created / '.$run->chunks_reused.' reused / '.$run->chunks_deleted.' deleted');
        $this->components->twoColumnDetail('embedded', $run->chunks_embedded.' of '.$run->chunks_total);
        $this->components->twoColumnDetail('tokens', number_format($run->tokens_used));
        $this->components->twoColumnDetail('cost', CostCalculator::format($run->cost_micros, 4));
        $this->components->twoColumnDetail('duration', ($run->durationSeconds() ?? 0).'s');

        if ($run->last_error !== null) {
            $this->newLine();
            $this->components->error($run->last_error);
        }

        $failed = $run->items()->where('status', 'failed')->limit(10)->get();

        if ($failed->isNotEmpty()) {
            $this->newLine();
            $this->components->warn('Failed documents:');

            $this->table(
                ['External id', 'Error'],
                $failed->map(static fn ($item): array => [$item->external_id, $item->error])->all(),
            );
        }
    }

    /**
     * A missing ANN index turns every query into a sequential scan, which is
     * invisible on a small corpus and catastrophic on a large one.
     */
    private function warnAboutIndex(): void
    {
        if (config('rag.vector.driver') !== 'pgvector') {
            return;
        }

        try {
            $connection = DB::connection(Tables::connection());

            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            $chunks = Tables::chunks();

            $index = $connection->selectOne(
                'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
                [$chunks, '%USING hnsw%'],
            ) ?? $connection->selectOne(
                'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
                [$chunks, '%USING ivfflat%'],
            );

            if ($index === null && Chunk::query()->count() > 1000) {
                $this->newLine();
                $this->components->warn(
                    'No ANN index on the embedding column: every search is a sequential scan. '
                    .'Run `php artisan rag:vector:reindex`.'
                );
            }
        } catch (\Throwable) {
            // Diagnostics must never be the reason a status command fails.
        }
    }
}
