<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

/**
 * One form field, as a write tool argument.
 */
final readonly class FieldBlueprint
{
    public const STRING = 'string';

    public const INTEGER = 'integer';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const ARRAY = 'array';

    /**
     * @param  array<int, mixed>  $rules  Laravel validation rules, as the form declares them
     * @param  array<string, string>|null  $options  value => label, when the field offers a fixed choice
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = self::STRING,
        public bool $required = false,
        public array $rules = [],
        public ?array $options = null,
        public ?string $format = null,
        public bool $relationship = false,
        public ?string $relationshipName = null,
        public ?string $titleAttribute = null,
        public bool $multiple = false,
    ) {}
}
