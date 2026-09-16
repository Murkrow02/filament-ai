<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Tables\Filters\BaseFilter;

/**
 * One of the table's filters, as a list tool argument.
 *
 * The filter object itself is carried along: narrowing is done by handing the
 * value back to Filament's own `apply()`, so a relationship filter, a scope or
 * a custom query callback behaves exactly as it does in the table instead of
 * being re-implemented as SQL here.
 */
final readonly class FilterBlueprint
{
    public const ENUM = 'enum';

    public const BOOLEAN = 'boolean';

    public const TEXT = 'text';

    /**
     * @param  array<string, string>|null  $options  value => label
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public BaseFilter $filter,
        public ?array $options = null,
        public bool $multiple = false,
    ) {}

    /**
     * The state shape Filament's filters read: a select keeps one `value`, a
     * multiple select keeps `values`.
     */
    public function state(mixed $value): array
    {
        return $this->multiple
            ? ['values' => array_values((array) $value)]
            : ['value' => $value];
    }

    public function description(): string
    {
        $description = $this->label;

        if ($this->options !== null && $this->options !== []) {
            $choices = [];

            foreach ($this->options as $value => $label) {
                $choices[] = $value === $label ? (string) $value : "{$value} = {$label}";
            }

            $description .= '. One of: '.implode(', ', $choices);
        }

        return $description.'.';
    }
}
