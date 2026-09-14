<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One declarative narrowing of a knowledge source's document query.
 *
 * A filter is the single definition behind three surfaces: the Eloquent
 * constraint applied during ingestion, the `rag:ingest --filter=name:value`
 * option, and the Filament ingestion form field. The package core knows only
 * this interface; mapping a filter to a form control happens in the Filament
 * layer, so nothing here depends on Filament.
 */
interface SourceFilter
{
    /**
     * Stable identifier used by the CLI option and the form state path.
     */
    public function name(): string;

    public function label(): string;

    /**
     * Applied when the caller supplies no value. Null means "no constraint".
     */
    public function default(): mixed;

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, mixed $value): void;
}
