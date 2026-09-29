<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Support\FullText;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Fill the full-text column in batches: after the migration on a large corpus
 * (which leaves it to this command), or with --rebuild after
 * `tsvector_language` changed or `unaccent` became available. Safe to run on
 * a live database and to interrupt; it resumes where it stopped.
 */
class FullTextCommand extends Command
{
    protected $signature = 'ai:fulltext
                            {--rebuild : Recompute every chunk, not only the missing ones (after changing the language)}
                            {--batch=2000 : Chunks per update statement}
                            {--drop : Remove the column, trigger, index and functions instead}';

    protected $description = 'Install and fill the full-text column used by hybrid retrieval';

    public function handle(): int
    {
        /** @var Connection $connection */
        $connection = DB::connection(Tables::connection());

        if ($connection->getDriverName() !== 'pgsql') {
            $this->components->error('The full-text column needs PostgreSQL.');

            return self::FAILURE;
        }

        if ($this->option('drop')) {
            FullText::uninstall($connection);
            $this->components->info('Full-text column removed.');

            return self::SUCCESS;
        }

        $this->components->task(
            'Installing '.FullText::COLUMN.' ('.FullText::language().')',
            static fn () => FullText::install($connection),
        );

        $rebuild = (bool) $this->option('rebuild');
        $bar = $this->output->createProgressBar(
            $rebuild ? $connection->table(Tables::chunks())->count() : $connection->table(Tables::chunks())->whereNull(FullText::COLUMN)->count(),
        );

        $done = FullText::backfill($connection, $rebuild, (int) $this->option('batch'), static fn (int $n) => $bar->setProgress($n));

        $bar->finish();
        $this->newLine();
        $this->components->twoColumnDetail('chunks filled', number_format($done));

        $words = 0;
        $this->components->task('Counting word frequencies', static function () use ($connection, &$words): void {
            $words = FullText::refreshStatistics($connection);
        });
        $this->components->twoColumnDetail('distinct words', number_format($words));

        $unaccent = $connection->selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'unaccent'") !== null;
        $this->components->twoColumnDetail('accent folding', $unaccent ? 'unaccent' : 'off (extension unavailable)');

        return self::SUCCESS;
    }
}
