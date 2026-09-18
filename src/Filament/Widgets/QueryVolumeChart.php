<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Murkrow\FilamentAi\Filament\Concerns\HasAiNavigation;
use Murkrow\FilamentAi\Models\QueryLog;

/**
 * Answered versus refused questions per day.
 *
 * A rising refusal rate is the earliest signal that the corpus has drifted
 * away from what people are actually asking about.
 */
class QueryVolumeChart extends ChartWidget
{
    use HasAiNavigation;

    protected ?string $heading = 'Questions per day';

    protected int|string|array $columnSpan = 'full';

    protected function getPollingInterval(): ?string
    {
        return static::aiPollIntervalWhileRunning();
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
        $since = now()->subDays(29)->startOfDay();

        $queries = QueryLog::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'refused']);

        $answered = [];
        $refused = [];
        $labels = [];

        for ($cursor = $since; $cursor <= now(); $cursor = $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d/m');

            $ofDay = $queries->filter(
                static fn (QueryLog $query): bool => $query->created_at?->format('Y-m-d') === $key,
            );

            $answered[] = $ofDay->where('refused', false)->count();
            $refused[] = $ofDay->where('refused', true)->count();
        }

        return [
            'datasets' => [
                ['label' => 'Answered', 'data' => $answered],
                ['label' => 'Refused', 'data' => $refused],
            ],
            'labels' => $labels,
        ];
    }
}
