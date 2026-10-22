<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A closed range of days, from the start of `from` to the end of `to`.
 *
 * `unit` is set when the range is made of whole calendar units (a month, two
 * weeks, a year) or is a running one ("this month so far"): the previous
 * period is then the same stretch one unit -- or as many units as it spans --
 * earlier, so "September" compares with August and "this month so far" with
 * the same days of last month, rather than with the 30 days before.
 */
final readonly class Period
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $label,
        public ?string $unit = null,
        public int $units = 1,
    ) {}

    public static function days(CarbonImmutable $from, CarbonImmutable $to, string $label): self
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();

        foreach (['year', 'quarter', 'month', 'week'] as $unit) {
            $units = self::wholeUnits($from, $to, $unit);

            if ($units !== null) {
                return new self($from, $to, $label, $unit, $units);
            }
        }

        return new self($from, $to, $label);
    }

    public function previous(): self
    {
        if ($this->unit === null) {
            $days = (int) $this->from->diffInDays($this->to->startOfDay()) + 1;

            return new self($this->from->subDays($days), $this->to->subDays($days), 'previous period');
        }

        $from = self::shift($this->from, $this->unit, -$this->units);
        $to = self::shift($this->to, $this->unit, -$this->units);

        // A whole month back from the 31st lands on the 30th or the 28th:
        // keep whole units whole.
        if (self::wholeUnits($this->from, $this->to, $this->unit) !== null) {
            $to = self::shift($from, $this->unit, $this->units)->subDay()->endOfDay();
        }

        return new self($from, $to, 'previous period', $this->unit, $this->units);
    }

    public function previousYear(): self
    {
        $from = $this->from->subYear();
        $to = $this->to->subYear();

        if ($this->unit !== null && self::wholeUnits($this->from, $this->to, $this->unit) !== null) {
            $to = self::shift($from, $this->unit, $this->units)->subDay()->endOfDay();
        }

        return new self($from, $to, 'same period last year', $this->unit, $this->units);
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'label' => $this->label];
    }

    /**
     * Moves a date by whole calendar units, never overflowing into the next
     * month (31 October minus a month is 30 September).
     */
    public static function shift(CarbonImmutable $date, string $unit, int $units): CarbonImmutable
    {
        return match ($unit) {
            'day' => $date->addDays($units),
            'week' => $date->addWeeks($units),
            'month' => $date->addMonthsNoOverflow($units),
            'quarter' => $date->addMonthsNoOverflow(3 * $units),
            'year' => $date->addYearsNoOverflow($units),
        };
    }

    private static function wholeUnits(CarbonImmutable $from, CarbonImmutable $to, string $unit): ?int
    {
        $start = match ($unit) {
            'week' => $from->startOfWeek(CarbonInterface::MONDAY),
            'month' => $from->startOfMonth(),
            'quarter' => $from->startOfQuarter(),
            'year' => $from->startOfYear(),
            default => $from->startOfDay(),
        };

        if (! $start->equalTo($from)) {
            return null;
        }

        for ($units = 1; $units <= 60; $units++) {
            $end = self::shift($from, $unit, $units)->subDay()->endOfDay();

            if ($end->equalTo($to)) {
                return $units;
            }

            if ($end->greaterThan($to)) {
                return null;
            }
        }

        return null;
    }
}
