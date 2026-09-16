<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Models\Chunk;

/**
 * Chunks embedded per hour over the last day.
 *
 * The useful reading is the shape, not the total: a flat line during a run
 * means the workers have stalled or the provider is throttling, which is
 * otherwise indistinguishable from "still working" on a progress bar.
 */
class IngestionThroughputChart extends ChartWidget
{
    use HasRagNavigation;

    protected ?string $heading = 'Embedding throughput (last 24h)';

    protected int|string|array $columnSpan = 'full';

    protected function getPollingInterval(): ?string
    {
        return static::ragPollIntervalWhileRunning();
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $since = now()->subDay()->startOfHour();

        $counts = Chunk::query()
            ->whereNotNull('embedded_at')
            ->where('embedded_at', '>=', $since)
            ->get(['embedded_at'])
            ->groupBy(static fn (Chunk $chunk): string => $chunk->embedded_at?->format('Y-m-d H') ?? '')
            ->map->count();

        $labels = [];
        $values = [];

        for ($cursor = $since; $cursor <= now(); $cursor = $cursor->addHour()) {
            $key = $cursor->format('Y-m-d H');
            $labels[] = $cursor->format('H:i');
            $values[] = $counts[$key] ?? 0;
        }

        return [
            'datasets' => [[
                'label' => 'Chunks embedded',
                'data' => $values,
                'fill' => 'start',
                'tension' => 0.3,
            ]],
            'labels' => $labels,
        ];
    }

    public static function canView(): bool
    {
        return (bool) config('rag.filament.pages.dashboard', true);
    }
}
