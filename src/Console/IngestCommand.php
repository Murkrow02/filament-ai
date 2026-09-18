<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Ingestion\IngestionPlanner;
use Murkrow\FilamentAi\Ingestion\StartIngestionRun;
use Murkrow\FilamentAi\Ingestion\SyncIngestionRunner;
use Murkrow\FilamentAi\Sources\FilterInput;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Symfony\Component\Console\Helper\ProgressBar;

class IngestCommand extends Command
{
    protected $signature = 'ai:ingest
                            {source : The knowledge source key}
                            {--filter=* : Repeatable name:value filter, e.g. --filter=id_range:1-50}
                            {--mode=incremental : full, incremental or embeddings_only}
                            {--sync : Run in-process instead of dispatching to the queue}
                            {--dry-run : Only estimate the work and cost}
                            {--target-tokens= : Override the chunk size for this run}
                            {--overlap-tokens= : Override the chunk overlap for this run}';

    protected $description = 'Chunk and embed documents from a knowledge source';

    public function handle(
        SourceRegistry $sources,
        IngestionPlanner $planner,
        StartIngestionRun $start,
        SyncIngestionRunner $sync,
    ): int {
        $key = (string) $this->argument('source');

        if (! $sources->has($key)) {
            $this->components->error("Unknown source [{$key}]. Run `php artisan ai:sources` to see what is configured.");

            return self::FAILURE;
        }

        $source = $sources->get($key);
        $filters = FilterInput::parseCli((array) $this->option('filter'));
        $mode = IngestionMode::tryFrom((string) $this->option('mode'));

        if ($mode === null) {
            $this->components->error('Invalid --mode. Use one of: '.implode(', ', array_column(IngestionMode::cases(), 'value')));

            return self::FAILURE;
        }

        $overrides = array_filter([
            'target_tokens' => $this->option('target-tokens') === null ? null : (int) $this->option('target-tokens'),
            'overlap_tokens' => $this->option('overlap-tokens') === null ? null : (int) $this->option('overlap-tokens'),
        ], static fn (mixed $v): bool => $v !== null);

        $estimate = $planner->estimate($source, $filters);

        $this->components->twoColumnDetail('source', $source->label()." <fg=gray>({$key})</>");
        $this->components->twoColumnDetail('mode', $mode->label());
        $this->components->twoColumnDetail('filters', $filters === [] ? '<fg=gray>none</>' : json_encode($filters));
        $this->components->twoColumnDetail('documents', number_format($estimate->documents));
        $this->components->twoColumnDetail('estimated chunks', '~'.number_format($estimate->chunks));
        $this->components->twoColumnDetail('estimated tokens', '~'.number_format($estimate->tokens));
        $this->components->twoColumnDetail('estimated cost', '~'.CostCalculator::format($estimate->costMicros, 4));

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was queued.');

            return self::SUCCESS;
        }

        if ($estimate->documents === 0) {
            $this->components->warn('No documents match those filters.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $this->option('sync')) {
            $run = $start($source, $filters, $mode, $overrides);

            $this->components->info("Queued run {$run->uuid} ({$run->documents_total} documents).");
            $this->components->bulletList([
                'Worker: php artisan queue:work '.config('filament-ai.queue.connection').' --queue='.config('filament-ai.queue.queue'),
                'Progress: php artisan ai:status --run='.$run->uuid,
            ]);

            return self::SUCCESS;
        }

        $bar = null;

        $run = $sync->run($source, $filters, $mode, $overrides, function (string $stage, int $done, int $total) use (&$bar): void {
            if ($bar === null || $bar->getMaxSteps() !== $total) {
                $bar?->finish();
                $this->newLine();
                $bar = $this->output->createProgressBar($total);
                $bar->setFormat(" {$stage}: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%");
                $bar->start();
            }

            $bar->setProgress($done);
        });

        if ($bar instanceof ProgressBar) {
            $bar->finish();
        }

        $this->newLine(2);

        $this->components->twoColumnDetail('documents processed', (string) $run->documents_done);
        $this->components->twoColumnDetail('documents skipped', (string) $run->documents_skipped);
        $this->components->twoColumnDetail('documents failed', (string) $run->documents_failed);
        $this->components->twoColumnDetail('chunks created', (string) $run->chunks_created);
        $this->components->twoColumnDetail('chunks reused', (string) $run->chunks_reused);
        $this->components->twoColumnDetail('chunks deleted', (string) $run->chunks_deleted);
        $this->components->twoColumnDetail('chunks embedded', (string) $run->chunks_embedded);
        $this->components->twoColumnDetail('tokens used', number_format($run->tokens_used));
        $this->components->twoColumnDetail('cost', CostCalculator::format($run->cost_micros, 4));

        return $run->documents_failed > 0 && $run->documents_done === 0 ? self::FAILURE : self::SUCCESS;
    }
}
