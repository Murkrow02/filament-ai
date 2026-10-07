<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Support;

/**
 * The one answer to "is the knowledge base on?".
 *
 * A host that wants only the panel agent -- resource tools, chat, approvals --
 * sets `FILAMENT_AI_KNOWLEDGE_ENABLED=false`. The vector store then resolves to
 * the `null` driver, which needs no pgvector, so the package migrates on MySQL,
 * MariaDB or SQLite; the knowledge pages, tools, commands and MCP server are
 * not registered. Choosing the `null` vector driver directly means the same.
 */
final class Knowledge
{
    public static function enabled(): bool
    {
        return (bool) config('filament-ai.knowledge.enabled', true)
            && config('filament-ai.vector.driver', 'pgvector') !== 'null';
    }

    public static function disabledMessage(): string
    {
        return 'The knowledge base is disabled (FILAMENT_AI_KNOWLEDGE_ENABLED=false or the null vector driver).';
    }
}
