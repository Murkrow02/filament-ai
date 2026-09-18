<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models\Concerns;

use Murkrow\FilamentAi\Support\Tables;

/**
 * Binds a model to the package's configured connection and prefixed table.
 *
 * Implemented as a trait rather than a base class so a host can still swap in
 * its own model by extending ours.
 */
trait UsesAiConnection
{
    /**
     * The config key under rag.database.tables this model maps to.
     */
    abstract protected function aiTableKey(): string;

    public function getConnectionName(): ?string
    {
        return $this->connection ?? Tables::connection();
    }

    public function getTable(): string
    {
        return $this->table ?? Tables::name($this->aiTableKey());
    }
}
