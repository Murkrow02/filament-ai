<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Resources\Resource;
use InvalidArgumentException;

/**
 * What the agent may do with one resource, and how.
 *
 * Starts from the defaults -- list, view, create and edit; delete only when
 * asked for -- and is narrowed by the resource's `agentTools()`. Every write
 * is approved by the user before it runs; create and edit can opt out of that,
 * delete cannot. Anything left null is derived from the resource itself.
 */
final class AgentTools
{
    public const LIST = 'list';

    public const VIEW = 'view';

    public const CREATE = 'create';

    public const EDIT = 'edit';

    public const DELETE = 'delete';

    public const ABILITIES = [self::LIST, self::VIEW, self::CREATE, self::EDIT, self::DELETE];

    public const DEFAULTS = [self::LIST, self::VIEW, self::CREATE, self::EDIT];

    /** A page of records the model can still read in one tool result. */
    public const MAX_RECORDS = 200;

    /** @var list<string> */
    private array $abilities = self::DEFAULTS;

    /** @var list<string> */
    private array $unapproved = [];

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
     * Add abilities to the current set, e.g. `->with(AgentTools::DELETE)`.
     */
    public function with(string ...$abilities): self
    {
        $this->abilities = array_values(array_intersect(self::ABILITIES, [...$this->abilities, ...$this->validated($abilities)]));

        return $this;
    }

    /**
     * Run these writes without asking the user first. Only for create and
     * edit: a deletion is always approved.
     */
    public function withoutApproval(string ...$abilities): self
    {
        foreach ($this->validated($abilities) as $ability) {
            if (! in_array($ability, [self::CREATE, self::EDIT], true)) {
                throw new InvalidArgumentException("Only create and edit can run without approval; [{$ability}] on [{$this->resource}] cannot.");
            }
        }

        $this->unapproved = array_values(array_unique([...$this->unapproved, ...$abilities]));

        return $this;
    }

    /**
     * Ask the user again before these writes, undoing `withoutApproval()`.
     */
    public function requireApproval(string ...$abilities): self
    {
        $this->unapproved = array_values(array_diff($this->unapproved, $this->validated($abilities)));

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
        $this->limit = max(1, min(self::MAX_RECORDS, $limit));

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
     * @return list<string>
     */
    public function unapprovedAbilities(): array
    {
        return $this->unapproved;
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
