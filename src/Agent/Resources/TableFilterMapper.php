<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Maps a Filament table filter to a list tool argument.
 *
 * Only select-shaped filters are offered: they carry their own choices and
 * their own query, so the agent narrows exactly the way the table does. A
 * custom `Filter::query()` needs form state the agent does not have, so it is
 * left out rather than guessed at.
 */
final class TableFilterMapper
{
    /**
     * Arguments the list tool already defines. A filter that happens to be
     * named like one of them is skipped instead of shadowing it.
     */
    private const RESERVED = ['page', 'per_page', 'search'];

    private const MAX_OPTIONS = 100;

    public function map(BaseFilter $filter): ?FilterBlueprint
    {
        // TernaryFilter extends SelectFilter, so anything else select-shaped
        // is covered by this one check.
        if (! $filter instanceof SelectFilter) {
            return null;
        }

        $name = $this->attempt(fn (): string => $filter->getName(), '');

        if ($name === '' || str_contains($name, '.') || in_array($name, self::RESERVED, true)) {
            return null;
        }

        $label = $this->label($filter, $name);

        if ($filter instanceof TernaryFilter) {
            return new FilterBlueprint($name, $label, FilterBlueprint::BOOLEAN, $filter);
        }

        $multiple = $this->attempt(fn (): bool => $filter->isMultiple(), false);
        $options = $this->options($filter);

        if ($options === null) {
            // A relationship filter builds its options from a query that needs
            // the table's Livewire component; its `apply()` does not, so the
            // argument survives as the related record's key.
            return $this->attempt(fn (): bool => $filter->queriesRelationships(), false)
                ? new FilterBlueprint($name, $label.' (id)', FilterBlueprint::TEXT, $filter, multiple: $multiple)
                : null;
        }

        return new FilterBlueprint($name, $label, FilterBlueprint::ENUM, $filter, $options, $multiple);
    }

    /**
     * @return array<string, string>|null
     */
    private function options(SelectFilter $filter): ?array
    {
        $options = $this->attempt(fn (): array => $filter->getOptions(), []);
        $flat = [];

        foreach ($options as $value => $label) {
            if (is_array($label)) {
                foreach ($label as $groupValue => $groupLabel) {
                    $flat[(string) $groupValue] = $this->plain($groupLabel);
                }

                continue;
            }

            $flat[(string) $value] = $this->plain($label);
        }

        return $flat === [] || count($flat) > self::MAX_OPTIONS ? null : $flat;
    }

    private function label(BaseFilter $filter, string $name): string
    {
        $label = $this->attempt(fn (): string|Htmlable|null => $filter->getLabel(), null);

        return $label === null || $label === '' ? Str::headline($name) : $this->plain($label);
    }

    private function plain(mixed $value): string
    {
        return $value instanceof Htmlable ? trim(strip_tags($value->toHtml())) : (string) $value;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default
     * @return T
     */
    private function attempt(callable $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }
}
