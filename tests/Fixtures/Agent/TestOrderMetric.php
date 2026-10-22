<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Agent;

use Illuminate\Database\Eloquent\Builder;
use Murkrow\FilamentAi\Agent\Metrics\Metric;
use Murkrow\FilamentAi\Agent\Metrics\MetricDefinition;
use Murkrow\FilamentAi\Tests\Fixtures\TestOrder;

/**
 * Book sales: completed orders only, amounts in cents.
 */
final class TestOrderMetric extends Metric
{
    public static bool $showAuthors = true;

    public function query(): Builder
    {
        return TestOrder::query()->where('status', 'completed');
    }

    public function define(MetricDefinition $metric): void
    {
        $metric->label('Vendite')
            ->describe('Completed orders only.')
            ->date('ordered_at')
            ->count('orders', 'Ordini')
            ->sum('revenue', 'amount', 'Incasso', unit: 'EUR', divisor: 100)
            ->sum('pieces', 'quantity', 'Pezzi')
            ->dimension('seller', 'seller', 'Venditore')
            ->dimension('book', 'book.title', 'Libro')
            ->dimension('author', 'book.author', 'Autore', when: fn (): bool => self::$showAuthors)
            ->filter('seller', 'Only this seller', fn (Builder $query, string $seller) => $query->where('seller', $seller), ['Lucia' => 'Lucia', 'Marco' => 'Marco'])
            ->periods(['saldi_estivi_2026' => ['2026-07-01', '2026-08-31']]);
    }
}
