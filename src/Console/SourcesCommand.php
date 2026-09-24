<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

class SourcesCommand extends Command
{
    protected $signature = 'ai:sources';

    protected $description = 'List the configured knowledge sources and their filters';

    public function handle(SourceRegistry $registry): int
    {
        $keys = $registry->keys();

        if ($keys === []) {
            $this->components->warn('No knowledge sources configured. Generate one with `php artisan ai:make:source` and list it under filament-ai.sources.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($keys as $key) {
            try {
                $source = $registry->get($key);

                $rows[] = [
                    $key,
                    $source->label(),
                    (string) Document::query()->where('source_key', $key)->count(),
                    implode(', ', $source->filterSet()->names()) ?: '-',
                ];
            } catch (Throwable $exception) {
                $rows[] = [$key, '<fg=red>'.$exception->getMessage().'</>', '-', '-'];
            }
        }

        $this->table(['Key', 'Label', 'Indexed documents', 'Filters'], $rows);

        return self::SUCCESS;
    }
}
