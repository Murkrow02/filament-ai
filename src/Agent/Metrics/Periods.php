<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Murkrow\FilamentAi\Agent\Tools\ToolRefusal;
use Throwable;

/**
 * Turns a metric's period arguments into a `Period`: a preset ("this_month"),
 * one of the application's named periods ("PE 2026"), or explicit dates.
 *
 * Running presets ("this_week", "this_month", "this_year") stop at today and
 * compare with the same stretch of the previous unit.
 */
final class Periods
{
    public const PRESETS = [
        'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'this_quarter', 'last_quarter', 'this_year', 'last_year', 'last_7_days', 'last_30_days',
    ];

    /**
     * @param  array<string, array{0: string, 1: string}>  $named
     */
    public function resolve(?string $period, ?string $from, ?string $to, array $named = [], ?CarbonImmutable $today = null): Period
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        if (filled($from) || filled($to)) {
            $start = $this->date($from ?? $to, 'from');
            $end = $this->date($to ?? $from, 'to');

            if ($end->lessThan($start)) {
                throw new ToolRefusal('"to" is before "from".');
            }

            return Period::days($start, $end, $start->toDateString().' – '.$end->toDateString());
        }

        $period = blank($period) ? 'this_month' : (string) $period;

        if (array_key_exists($period, $named)) {
            [$start, $end] = $named[$period];

            return Period::days($this->date($start, $period), $this->date($end, $period), $period);
        }

        return match ($period) {
            'today' => new Period($today, $today->endOfDay(), $period, 'day'),
            'yesterday' => new Period($today->subDay(), $today->subDay()->endOfDay(), $period, 'day'),
            'this_week' => new Period($today->startOfWeek(CarbonInterface::MONDAY), $today->endOfDay(), $period, 'week'),
            'last_week' => Period::days($today->subWeek()->startOfWeek(CarbonInterface::MONDAY), $today->subWeek()->endOfWeek(CarbonInterface::SUNDAY), $period),
            'this_month' => new Period($today->startOfMonth(), $today->endOfDay(), $period, 'month'),
            'last_month' => Period::days($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth(), $period),
            'this_quarter' => new Period($today->startOfQuarter(), $today->endOfDay(), $period, 'quarter'),
            'last_quarter' => Period::days($today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter(), $period),
            'this_year' => new Period($today->startOfYear(), $today->endOfDay(), $period, 'year'),
            'last_year' => Period::days($today->subYear()->startOfYear(), $today->subYear()->endOfYear(), $period),
            'last_7_days' => new Period($today->subDays(6), $today->endOfDay(), $period),
            'last_30_days' => new Period($today->subDays(29), $today->endOfDay(), $period),
            default => throw new ToolRefusal("unknown period [{$period}]. Use one of: ".implode(', ', [...self::PRESETS, ...array_keys($named)]).', or "from"/"to".'),
        };
    }

    private function date(?string $value, string $argument): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $value) ?: throw new ToolRefusal('');
        } catch (Throwable) {
            throw new ToolRefusal("\"{$argument}\" must be a date as YYYY-MM-DD.");
        }
    }
}
