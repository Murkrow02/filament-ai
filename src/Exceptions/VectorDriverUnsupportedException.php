<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

class VectorDriverUnsupportedException extends RagException
{
    public static function driver(string $driver): self
    {
        return new self("Unsupported vector store driver [{$driver}].");
    }

    public static function connection(string $driver, string $actual): self
    {
        return new self(
            "The [{$driver}] vector store requires a PostgreSQL connection, but the configured "
            ."connection uses the [{$actual}] driver. Set rag.database.connection to a pgsql connection."
        );
    }

    public static function extensionMissing(): self
    {
        return new self(
            'The pgvector extension is not installed on this database. Run '
            .'`CREATE EXTENSION IF NOT EXISTS vector;` as a superuser, or use an image that ships it '
            .'(for example pgvector/pgvector:pg17).'
        );
    }
}
