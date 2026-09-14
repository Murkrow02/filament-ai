<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Models\Chunk;

/**
 * Embedded versus pending chunks, per source.
 *
 * Aggregate coverage hides the case that matters: one source fully indexed and
 * another untouched still reads as "70% done".
 */
class SourceCoverageChart extends ChartWidget
{
    use HasRagNavigation;

    protected ?string $heading = 'Coverage by source';

    protected function getPollingInterval(): ?string
    {
        return static::ragPollIntervalWhileRunning();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = Chunk::query()
            ->select('source_key')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(embedded_at) as embedded')
            ->groupBy('source_key')
            ->orderBy('source_key')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Embedded',
                    'data' => $rows->pluck('embedded')->map(intval(...))->all(),
                ],
                [
                    'label' => 'Pending',
                    'data' => $rows->map(static fn ($row): int => (int) $row->total - (int) $row->embedded)->all(),
                ],
            ],
            'labels' => $rows->pluck('source_key')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
        ];
    }

    public static function canView(): bool
    {
        return (bool) config('rag.filament.pages.dashboard', true);
    }
}
