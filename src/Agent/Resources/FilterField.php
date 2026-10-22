<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

/**
 * One field of a filter's own form, as a list tool argument.
 *
 * A filter like "sold between" keeps its state in fields it declares itself
 * (`from`, `until`); each becomes an argument of its own, named after the
 * filter, and its value is put back at the field's state path before the
 * filter's query runs.
 */
final readonly class FilterField
{
    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public const STRING = 'string';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const ENUM = 'enum';

    /**
     * @param  array<string, string>|null  $options  value => label, for ENUM
     */
    public function __construct(
        public string $argument,
        public string $statePath,
        public string $label,
        public string $type,
        public ?array $options = null,
        public bool $multiple = false,
    ) {}

    public function description(): string
    {
        $description = $this->label;

        $description .= match ($this->type) {
            self::DATE => ' (YYYY-MM-DD)',
            self::DATETIME => ' (YYYY-MM-DD HH:MM)',
            default => '',
        };

        if ($this->options !== null && $this->options !== []) {
            $description .= '. One of: '.FilterBlueprint::choices($this->options);
        }

        return $description.'.';
    }
}
