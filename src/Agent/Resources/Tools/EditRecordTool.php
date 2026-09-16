<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\Tools\Concerns\WritesRecords;

/**
 * Changes fields of one record, with the form's validation, as the current
 * panel user. Asks the user to approve before it runs unless the resource
 * turned that off for edits.
 */
final class EditRecordTool implements Approvable, Tool
{
    use InteractsWithApprovals;
    use WritesRecords;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_edit';
    }

    public function description(): string
    {
        $text = "Change fields of one existing {$this->blueprint->label}, by its id. Send only the fields to change. The user confirms the change before it is saved.";

        return $this->blueprint->description === null ? $text : $this->blueprint->description.' '.$text;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description("The {$this->blueprint->label} id.")->required(),
            ...$this->fieldSchemas($schema, honourRequired: false),
        ];
    }

    public function handle(Request $request): string
    {
        $resource = $this->blueprint->resource;
        $id = trim((string) ($request->all()['id'] ?? ''));
        $record = $id === '' ? null : $resource::getEloquentQuery()->whereKey($id)->first();

        if ($record === null) {
            return "Error: no {$this->blueprint->label} with id [{$id}].";
        }

        if (! $resource::canEdit($record)) {
            return "Error: the current user is not allowed to edit this {$this->blueprint->label}.";
        }

        $values = $this->fieldValues($request);

        if ($values === []) {
            return 'Error: no field to change was given.';
        }

        if (($error = $this->validationError($values, partial: true)) !== null) {
            return $error;
        }

        DB::transaction(fn () => $record->update($values));

        return json_encode([
            'updated' => true,
            'record' => RecordPresenter::present($record->refresh(), $this->blueprint, $this->blueprint->viewAttributes),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        if (! $this->blueprint->requiresApproval(AgentTools::EDIT)) {
            return false;
        }

        return Approval::required($this->summarise('Edit', $this->fieldValues($request), '#'.($request->all()['id'] ?? '?')));
    }
}
