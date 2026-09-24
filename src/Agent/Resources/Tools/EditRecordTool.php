<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\Tools\Concerns\ResolvesRecord;
use Murkrow\FilamentAi\Agent\Resources\Tools\Concerns\WritesRecords;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;
use Throwable;

/**
 * Changes fields of one record through the resource's form, as the current
 * panel user. Asks the user to approve -- showing each field's current and new
 * value -- unless the resource turned that off for edits.
 */
final class EditRecordTool implements Approvable, Tool
{
    use GuardsToolFailures;
    use InteractsWithApprovals;
    use ResolvesRecord;
    use WritesRecords;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_edit';
    }

    public function description(): string
    {
        $text = "Change fields of one existing {$this->blueprint->label}, by its id. Send only the fields to change.";

        if ($this->blueprint->requiresApproval(AgentTools::EDIT)) {
            $text .= ' The user confirms the change before it is saved.';
        }

        return $this->blueprint->description === null ? $text : $this->blueprint->description.' '.$text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description("The {$this->blueprint->label} id.")->required(),
            ...$this->fieldSchemas($schema, AgentTools::EDIT, honourRequired: false),
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

            if (! $resource::canEdit($record)) {
                return "Error: the current user is not allowed to edit this {$this->blueprint->label}.";
            }

            $values = $this->submittedValues($request);

            if ($values === []) {
                return 'Error: no field to change was given.';
            }

            if ($this->wasRefused($request)) {
                $preview = $this->forms()->preview($resource, $record, $values);

                return $preview->passes() ? 'Error: the values could not be checked. Call the tool again.' : $preview->errorMessage();
            }

            $outcome = $this->forms()->update($resource, $record, $values);

            if (! $outcome->passes()) {
                return $outcome->errorMessage();
            }

            return json_encode([
                'updated' => $outcome->changes !== [],
                'changed' => array_keys($outcome->changes),
                'record' => RecordPresenter::present($record->refresh(), $this->blueprint, $this->blueprint->viewAttributes),
                ...$this->ignoredNote($outcome),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        });
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        try {
            $resource = $this->blueprint->resource;
            $record = $this->resolveRecord($request);
            $values = $this->submittedValues($request);
            $preview = is_string($record) || $values === [] || ! $resource::canEdit($record)
                ? null
                : $this->forms()->preview($resource, $record, $values);
        } catch (Throwable) {
            $preview = null;
        }

        if ($preview === null || ! $preview->passes()) {
            $this->refuse($request);

            return false;
        }

        if (! $this->blueprint->requiresApproval(AgentTools::EDIT)) {
            return false;
        }

        /** @var Model $record */
        return Approval::required($this->summarise('edit', $preview, RecordPresenter::titleFor($resource, $record)));
    }
}
