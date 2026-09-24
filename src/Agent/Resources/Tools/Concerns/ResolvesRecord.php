<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\RecordKey;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;

/**
 * Finds the record a tool call names, through the resource's own query.
 *
 * The id is checked against the key's type before it reaches SQL (see
 * `RecordKey`): a model that sends a title instead of an id deserves an
 * answer it can correct, not a failed turn. A record outside the resource's
 * query -- another tenant's, or one the host scoped out -- is "not found",
 * never "forbidden", so its existence does not leak.
 *
 * @property-read ResourceBlueprint $blueprint
 */
trait ResolvesRecord
{
    /**
     * @return Model|string the record, or an error the model can read
     */
    protected function resolveRecord(Request $request): Model|string
    {
        $resource = $this->blueprint->resource;
        $raw = $request->all()['id'] ?? null;
        $id = is_scalar($raw) ? trim((string) $raw) : '';

        if ($id === '') {
            return 'Error: the "id" argument is required.';
        }

        if (! RecordKey::valid(app($resource::getModel()), $id)) {
            return "Error: [{$this->shown($id)}] is not a valid {$this->blueprint->label} id. Use an id returned by {$this->blueprint->toolPrefix}_list.";
        }

        $record = $resource::getEloquentQuery()->whereKey($id)->first();

        return $record ?? "Error: no {$this->blueprint->label} with id [{$id}].";
    }

    private function shown(string $id): string
    {
        return Str::limit($id, 40);
    }
}
