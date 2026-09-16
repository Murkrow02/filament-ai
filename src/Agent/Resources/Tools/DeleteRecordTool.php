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
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;

/**
 * Deletes one record as the current panel user.
 *
 * Offered only when the resource asked for it, and always approved by the
 * user first: `needsApproval()` ignores the blueprint's approval settings.
 */
final class DeleteRecordTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(private readonly ResourceBlueprint $blueprint) {}

    public function name(): string
    {
        return $this->blueprint->toolPrefix.'_delete';
    }

    public function description(): string
    {
        return "Delete one {$this->blueprint->label} by its id. The user confirms the deletion before it happens.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description("The {$this->blueprint->label} id.")->required(),
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

        if (! $resource::canDelete($record)) {
            return "Error: the current user is not allowed to delete this {$this->blueprint->label}.";
        }

        $title = RecordPresenter::titleFor($resource, $record);

        DB::transaction(fn () => $record->delete());

        return json_encode(['deleted' => true, 'id' => $record->getKey(), 'title' => $title], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required("Delete {$this->blueprint->label} #".($request->all()['id'] ?? '?'));
    }
}
