<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Agent\Files\FileStore;

/**
 * Removes files the assistant's tools produced once they are older than
 * `filament-ai.files.keep_days`. Schedule it daily.
 */
final class PruneFilesCommand extends Command
{
    protected $signature = 'ai:files:prune';

    protected $description = 'Delete files produced by the assistant that are past their retention';

    public function handle(FileStore $files): int
    {
        $this->info('Removed '.$files->prune().' file(s).');

        return self::SUCCESS;
    }
}
