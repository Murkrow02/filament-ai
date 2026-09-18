<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Murkrow\FilamentAi\Filament\Concerns\HasAiNavigation;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Models\IngestionRun;
use Murkrow\FilamentAi\Models\QueryLog;

/**
 * The overview: corpus health, throughput, coverage and recent activity.
 *
 * Deliberately not built from Filament's widget system. Widgets are Livewire
 * components, and even with polling disabled they still round-trip through
 * `/livewire/update` for every interaction; several on one page racing the
 * panel's session/CSRF lifecycle is what produced the "This page has
 * expired" loop this replaced. Everything here is computed once in mount()
 * and rendered as static Blade -- a full page load is the only way to see
 * fresh numbers, which is the point.
 */
class KnowledgeDashboard extends Page
{
    use HasAiNavigation;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $title = 'Knowledge base';

    protected static ?string $navigationLabel = 'Overview';

    protected string $view = 'filament-ai::filament.pages.dashboard';

    /** @var array<string, mixed> */
    public array $stats = [];

    /** @var array<int, array<string, mixed>> */
    public array $coverageBySource = [];

    /** @var array<int, array<string, mixed>> */
    public array $throughputByHour = [];

    /** @var array<int, array<string, mixed>> */
    public array $queryVolumeByDay = [];

    /** @var array<int, array<string, mixed>> */
    public array $recentRuns = [];

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::aiSlug('dashboard');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('filament-ai.filament.navigation_sort', 90) - 1;
    }

    public function mount(): void
    {
        abort_unless(static::canAccessAi(), 403);

        $this->stats = $this->computeStats();
        $this->coverageBySource = $this->computeCoverageBySource();
        $this->throughputByHour = $this->computeThroughputByHour();
        $this->queryVolumeByDay = $this->computeQueryVolumeByDay();
        $this->recentRuns = $this->computeRecentRuns();
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStats(): array
    {
        $documents = Document::query()->count();
        $chunks = Chunk::query()->count();
        $embedded = Chunk::query()->whereNotNull('embedded_at')->count();
        $pending = $chunks - $embedded;

        $model = (string) config('filament-ai.embeddings.model');
        $dimensions = (int) config('filament-ai.embeddings.dimensions');

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
            'documents' => $documents,
            'chunks' => $chunks,
            'coverage' => $coverage,
            'embedded' => $embedded,
            'pending' => $pending,
            'stale' => $stale,
            'spend' => CostCalculator::format($spend, 2),
            'queries' => QueryLog::query()->count(),
            'avgLatency' => $avgLatency,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeCoverageBySource(): array
    {
        return Chunk::query()
            ->select('source_key')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(embedded_at) as embedded')
            ->groupBy('source_key')
            ->orderBy('source_key')
            ->get()
            ->map(static function ($row): array {
                $total = (int) $row->total;
                $embedded = (int) $row->embedded;

                return [
                    'source' => $row->source_key,
                    'total' => $total,
                    'embedded' => $embedded,
                    'pending' => $total - $embedded,
                    'percent' => $total > 0 ? (int) round($embedded / $total * 100) : 0,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeThroughputByHour(): array
    {
        $since = now()->subDay()->startOfHour();

        $counts = Chunk::query()
            ->whereNotNull('embedded_at')
            ->where('embedded_at', '>=', $since)
            ->get(['embedded_at'])
            ->groupBy(static fn (Chunk $chunk): string => $chunk->embedded_at?->format('Y-m-d H') ?? '')
            ->map->count();

        $rows = [];
        $max = 1;

        for ($cursor = $since; $cursor <= now(); $cursor = $cursor->addHour()) {
            $key = $cursor->format('Y-m-d H');
            $count = (int) ($counts[$key] ?? 0);
            $max = max($max, $count);
            $rows[] = ['label' => $cursor->format('H:i'), 'count' => $count];
        }

        foreach ($rows as &$row) {
            $row['percent'] = (int) round($row['count'] / $max * 100);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeQueryVolumeByDay(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $queries = QueryLog::query()
            ->where('created_at', '>=', $since)
            ->get(['created_at', 'refused']);

        $rows = [];
        $max = 1;

        for ($cursor = $since; $cursor <= now(); $cursor = $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');

            $ofDay = $queries->filter(
                static fn (QueryLog $query): bool => $query->created_at?->format('Y-m-d') === $key,
            );

            $answered = $ofDay->where('refused', false)->count();
            $refused = $ofDay->where('refused', true)->count();
            $max = max($max, $answered + $refused);

            $rows[] = [
                'label' => $cursor->format('d/m'),
                'answered' => $answered,
                'refused' => $refused,
            ];
        }

        foreach ($rows as &$row) {
            $row['answeredPercent'] = (int) round($row['answered'] / $max * 100);
            $row['refusedPercent'] = (int) round($row['refused'] / $max * 100);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeRecentRuns(): array
    {
        return IngestionRun::query()
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(static fn (IngestionRun $run): array => [
                'source' => $run->source_key,
                'statusLabel' => $run->status->label(),
                'statusColor' => $run->status->color(),
                'statusIcon' => $run->status->icon(),
                'progress' => $run->progressPercent(),
                'embedded' => number_format($run->chunks_embedded).' / '.number_format($run->chunks_total),
                'cost' => CostCalculator::format($run->cost_micros, 4),
                'started' => $run->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        if (! config('filament-ai.filament.pages.ingest', true)) {
            return [];
        }

        return [
            Action::make('ingest')
                ->label('Ingest knowledge')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(IngestKnowledge::getUrl()),
        ];
    }
}
