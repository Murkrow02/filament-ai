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
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\Tools\Concerns\ResolvesRecord;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;
use Throwable;

/**
 * Deletes one record as the current panel user.
 *
 * Offered only when the resource asked for it, and always approved by the
 * user first. `shouldRequestApproval()` is overridden rather than
 * `needsApproval()`: the trait's `withoutApproval()` would otherwise switch
 * the question off from outside.
 */
final class DeleteRecordTool implements Approvable, Tool
{
    use GuardsToolFailures;
    use InteractsWithApprovals;
    use ResolvesRecord;

    private ?string $refusedCall = null;

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
        return $this->guarded(function () use ($request): string {
            $resource = $this->blueprint->resource;
            $record = $this->resolveRecord($request);

            if (is_string($record)) {
                return $record;
            }

            if (! $resource::canDelete($record)) {
                return "Error: the current user is not allowed to delete this {$this->blueprint->label}.";
            }

            // Not put to the user because it could not be deleted then: never
            // delete it now without having asked.
            if ($this->refusedCall === $this->callKey($request)) {
                return 'Error: this deletion was not confirmed. Call the tool again.';
            }

            $title = RecordPresenter::titleFor($resource, $record);

            DB::transaction(fn () => $record->delete());

            return json_encode(['deleted' => true, 'id' => $record->getKey(), 'title' => $title], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        });
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        try {
            $resource = $this->blueprint->resource;
            $record = $this->resolveRecord($request);
            $deletable = ! is_string($record) && $resource::canDelete($record);
        } catch (Throwable) {
            $deletable = false;
        }

        if (! $deletable) {
            // handle() explains why, and deletes nothing.
            $this->refusedCall = $this->callKey($request);

            return null;
        }

        /** @var Model $record */
        return Approval::required((string) __('filament-ai::messages.approval.delete', [
            'label' => $this->blueprint->label,
            'title' => RecordPresenter::titleFor($resource, $record) ?? '#'.$record->getKey(),
        ]));
    }

    private function callKey(Request $request): string
    {
        return hash('xxh128', json_encode($request->all()));
    }
}
