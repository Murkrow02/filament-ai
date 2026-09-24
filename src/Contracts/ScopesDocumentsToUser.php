<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;

/**
 * An optional second contract for a knowledge source whose documents are not
 * all readable by everyone -- invoices that belong to a customer, files one
 * user uploaded.
 *
 * The assistant and the MCP tools search on behalf of whoever is signed in;
 * without this, every indexed document of an allowed source is readable by
 * every user who can reach the chat. The source narrows the documents table
 * for that user; the constraint is applied to searches and to reads alike.
 *
 *     public function scopeDocumentsFor(Builder $documents, ?Authenticatable $user, string $table): void
 *     {
 *         $documents->where("{$table}.metadata->user_id", $user?->getAuthIdentifier());
 *     }
 *
 * `$table` is the name or alias the documents table has in that query; use it
 * to qualify every column. An exception fails closed: the source is left out
 * of that search entirely.
 */
interface ScopesDocumentsToUser
{
    public function scopeDocumentsFor(Builder $documents, ?Authenticatable $user, string $table): void;
}
