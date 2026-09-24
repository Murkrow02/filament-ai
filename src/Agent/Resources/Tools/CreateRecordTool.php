<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\Tools\Concerns\WritesRecords;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;
use Throwable;

/**
 * Creates one record through the resource's form, as the current panel user.
 * Asks the user to approve before it runs unless the resource turned that off
 * for creation.
 *
 * The form runs exactly as on the panel's create page (see `FormPipeline`),
 * and the record is saved the way `CreateRecord::handleRecordCreation()` saves
 * it, so `$fillable`, model events and -- with the panel's tenant set -- tenant
 * ownership behave as they do from the page.
 */
final class CreateRecordTool implements Approvable, Tool
{
    use GuardsToolFailures;
    use InteractsWithApprovals;
    use WritesRecords;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_create';
    }

    public function description(): string
    {
        $text = "Create a new {$this->blueprint->label}.";

        if ($this->blueprint->requiresApproval(AgentTools::CREATE)) {
            $text .= ' The user confirms it before it is saved.';
        }

        return $this->blueprint->description === null ? $text : $this->blueprint->description.' '.$text;
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->fieldSchemas($schema, AgentTools::CREATE, honourRequired: true);
    }

    public function handle(Request $request): string
    {
        return $this->guarded(function () use ($request): string {
            $resource = $this->blueprint->resource;

            if (! $resource::canCreate()) {
                return "Error: the current user is not allowed to create {$this->blueprint->pluralLabel}.";
            }

            if ($this->wasRefused($request)) {
                $preview = $this->forms()->preview($resource, null, $this->submittedValues($request));

                return $preview->passes() ? 'Error: the values could not be checked. Call the tool again.' : $preview->errorMessage();
            }

            $outcome = $this->forms()->create($resource, $this->submittedValues($request));

            if (! $outcome->passes() || $outcome->record === null) {
                return $outcome->errorMessage();
            }

            return json_encode([
                'created' => true,
                'record' => RecordPresenter::present($outcome->record->refresh(), $this->blueprint, $this->blueprint->viewAttributes),
                ...$this->ignoredNote($outcome),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        });
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        try {
            $resource = $this->blueprint->resource;
            $preview = $resource::canCreate() ? $this->forms()->preview($resource, null, $this->submittedValues($request)) : null;
        } catch (Throwable) {
            $preview = null;
        }

        // Nothing to approve: handle() will explain why, and write nothing.
        if ($preview === null || ! $preview->passes()) {
            $this->refuse($request);

            return false;
        }

        if (! $this->blueprint->requiresApproval(AgentTools::CREATE)) {
            return false;
        }

        return Approval::required($this->summarise('create', $preview));
    }
}
