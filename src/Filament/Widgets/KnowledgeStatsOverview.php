<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\QueryLog;

/**
 * The five numbers that answer "is the knowledge base healthy?".
 *
 * Stale vectors get their own tile because they are the failure mode that
 * silently degrades answers: retrieval keeps working, it just stops finding
 * the right passages.
 */
class KnowledgeStatsOverview extends StatsOverviewWidget
{
    use HasRagNavigation;

    protected function getPollingInterval(): ?string
    {
        return static::ragPollIntervalWhileRunning();
    }

    protected function getStats(): array
    {
        $documents = Document::query()->count();
        $chunks = Chunk::query()->count();
        $embedded = Chunk::query()->whereNotNull('embedded_at')->count();
        $pending = $chunks - $embedded;

        $model = (string) config('rag.embeddings.model');
        $dimensions = (int) config('rag.embeddings.dimensions');

        $stale = Chunk::query()
            ->whereNotNull('embedded_at')
            ->where(function ($query) use ($model, $dimensions): void {
                $query->where('embedding_model', '!=', $model)
                    ->orWhere('embedding_dimensions', '!=', $dimensions);
            })
            ->count();

        $coverage = $chunks > 0 ? (int) round($embedded / $chunks * 100) : 0;

        $spend = (int) IngestionRun::query()->sum('cost_micros')
            + (int) QueryLog::query()->sum('cost_micros');

        $avgLatency = (int) round((float) QueryLog::query()->avg('latency_ms'));

        return [
            Stat::make('Documents', number_format($documents))
                ->description(number_format($chunks).' chunks')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('gray'),

            Stat::make('Coverage', $coverage.'%')
                ->description(number_format($embedded).' of '.number_format($chunks).' embedded')
                ->descriptionIcon($coverage >= 100 ? 'heroicon-m-check-circle' : 'heroicon-m-arrow-path')
                ->color($coverage >= 99 ? 'success' : ($coverage >= 60 ? 'warning' : 'danger')),

            Stat::make('Awaiting embedding', number_format($pending))
                ->description($pending > 0 ? 'Run an ingestion to finish' : 'Nothing queued')
                ->descriptionIcon('heroicon-m-queue-list')
                ->color($pending > 0 ? 'warning' : 'success'),

            Stat::make('Stale vectors', number_format($stale))
                ->description($stale > 0 ? 'Embedded with another model' : 'All current')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($stale > 0 ? 'danger' : 'success'),

            Stat::make('Spend to date', CostCalculator::format($spend, 2))
                ->description(number_format(QueryLog::query()->count()).' queries, avg '.$avgLatency.' ms')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray'),
        ];
    }

    public static function canView(): bool
    {
        return (bool) config('rag.filament.pages.dashboard', true);
    }
}
