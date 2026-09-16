<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
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
 * Creates one record from the resource's form fields, with the form's
 * validation, as the current panel user. Asks the user to approve before it
 * runs unless the resource turned that off for creation.
 *
 * The record is built the way Filament's own `CreateRecord::handleRecordCreation()`
 * builds it -- `new Model($data)` then `save()` -- so the model's `$fillable`
 * and its events behave exactly as they do from the panel's create page.
 */
final class CreateRecordTool implements Approvable, Tool
{
    use InteractsWithApprovals;
    use WritesRecords;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_create';
    }

    public function description(): string
    {
        $text = "Create a new {$this->blueprint->label}. The user confirms it before it is saved.";

        return $this->blueprint->description === null ? $text : $this->blueprint->description.' '.$text;
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->fieldSchemas($schema, honourRequired: true);
    }

    public function handle(Request $request): string
    {
        $resource = $this->blueprint->resource;

        if (! $resource::canCreate()) {
            return "Error: the current user is not allowed to create {$this->blueprint->pluralLabel}.";
        }

        $values = $this->fieldValues($request);

        if (($error = $this->validationError($values, partial: false)) !== null) {
            return $error;
        }

        $record = DB::transaction(function () use ($resource, $values): Model {
            $model = $resource::getModel();
            $record = new $model($values);
            $record->save();

            return $record;
        });

        return json_encode([
            'created' => true,
            'record' => RecordPresenter::present($record->refresh(), $this->blueprint, $this->blueprint->viewAttributes),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        if (! $this->blueprint->requiresApproval(AgentTools::CREATE)) {
            return false;
        }

        return Approval::required($this->summarise('Create', $this->fieldValues($request)));
    }
}
