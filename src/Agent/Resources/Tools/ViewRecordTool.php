<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\Tools\Concerns\ResolvesRecord;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;

/**
 * Reads one record of a resource, as the current panel user.
 *
 * The record is looked up through `Resource::getEloquentQuery()`, so one that
 * belongs to another tenant or was scoped out is simply not found -- the
 * answer does not reveal that it exists. The `view` policy is checked per call.
 */
final class ViewRecordTool implements Tool
{
    use GuardsToolFailures;
    use ResolvesRecord;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_view';
    }

    public function description(): string
    {
        $text = "Read every detail of one {$this->blueprint->label} by its id, as returned by {$this->blueprint->toolPrefix}_list.";

        return $this->blueprint->description === null ? $text : $this->blueprint->description.' '.$text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description("The {$this->blueprint->label} id.")->required(),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->guarded(function () use ($request): string {
            $resource = $this->blueprint->resource;
            $record = $this->resolveRecord($request);

            if (is_string($record)) {
                return $record;
            }

            if (! $resource::canView($record)) {
                return "Error: the current user is not allowed to view this {$this->blueprint->label}.";
            }

            return json_encode(
                RecordPresenter::present($record, $this->blueprint, $this->blueprint->viewAttributes),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        });
    }
}
