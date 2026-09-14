<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use InvalidArgumentException;

/**
 * What the agent may do with one resource, and how.
 *
 * Starts from everything derivable and is narrowed by the resource's
 * `agentTools()`. Anything left null is derived from the resource itself.
 */
final class AgentTools
{
    public const LIST = 'list';

    public const VIEW = 'view';

    public const ABILITIES = [self::LIST, self::VIEW];

    /** @var list<string> */
    private array $abilities = self::ABILITIES;

    /** @var list<string>|null */
    private ?array $searchColumns = null;

    /** @var list<string>|null */
    private ?array $attributes = null;

    private int $limit = 25;

    private ?string $description = null;

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public function __construct(public readonly string $resource) {}

    public function only(string ...$abilities): self
    {
        $this->abilities = array_values(array_intersect(self::ABILITIES, $this->validated($abilities)));

        return $this;
    }

    public function except(string ...$abilities): self
    {
        $this->abilities = array_values(array_diff($this->abilities, $this->validated($abilities)));

        return $this;
    }

    /**
     * Columns a free-text search matches, instead of the table's searchable
     * columns.
     *
     * @param  list<string>  $columns
     */
    public function searchUsing(array $columns): self
    {
        $this->searchColumns = array_values($columns);

        return $this;
    }

    /**
     * Attributes returned for each record, instead of the table's columns and
     * the form's fields. Attributes the model hides are never returned.
     *
     * @param  list<string>  $attributes
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = array_values($attributes);

        return $this;
    }

    /**
     * The most records one list call may return.
     */
    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    /**
     * Domain context for the model: what these records are and when to use
     * them. Goes into the tool descriptions.
     */
    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /**
     * @return list<string>|null
     */
    public function searchColumns(): ?array
    {
        return $this->searchColumns;
    }

    /**
     * @return list<string>|null
     */
    public function configuredAttributes(): ?array
    {
        return $this->attributes;
    }

    public function maxRecords(): int
    {
        return $this->limit;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @param  array<int, string>  $abilities
     * @return list<string>
     */
    private function validated(array $abilities): array
    {
        foreach ($abilities as $ability) {
            if (! in_array($ability, self::ABILITIES, true)) {
                throw new InvalidArgumentException("Unknown agent ability [{$ability}] on [{$this->resource}].");
            }
        }

        return array_values($abilities);
    }
}
