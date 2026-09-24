<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\FormPipeline;
use Murkrow\FilamentAi\Agent\Resources\RecordKey;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\ValueFormatter;
use Throwable;

/**
 * The card a person approves or rejects a change on.
 *
 * It answers the three questions the decision needs: what kind of change,
 * which record, and what each field becomes -- in the resource's own labels,
 * with the record's current value next to the new one, option labels instead
 * of keys and dates in the reader's format. The values come from the same
 * form run the write itself will do (`FormPipeline::preview()`), so the card
 * shows what would be saved, not what the model typed.
 *
 * The tool name and its raw arguments are added for the `debug` ability only.
 */
final class ApprovalCards
{
    public function __construct(
        private readonly ToolLabels $labels,
        private readonly FormPipeline $forms,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function card(string $id, string $tool, ?string $reason, array $arguments, bool $debug): array
    {
        [$blueprint, $action] = $this->labels->resourceTool($tool);

        $card = $blueprint === null
            ? $this->generic($tool, $reason, $arguments)
            : $this->forResource($blueprint, (string) $action, $reason, $arguments);

        $card['id'] = $id;

        if ($debug) {
            $card['tool'] = $tool;
            $card['arguments'] = $arguments;
        }

        return $card;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function forResource(ResourceBlueprint $blueprint, string $action, ?string $reason, array $arguments): array
    {
        $resource = $blueprint->resource;
        $model = $resource::getModel();
        $record = null;

        try {
            if ($action !== AgentTools::CREATE) {
                $record = $this->record($blueprint, $arguments['id'] ?? null);
            }

            $title = $record === null ? null : (RecordPresenter::titleFor($resource, $record) ?? '#'.$record->getKey());

            $card = [
                'title' => (string) __('filament-ai::messages.approval.'.$action, ['label' => $blueprint->label, 'title' => $title ?? '']),
                'record' => $title,
                'changes' => [],
                'summary' => null,
            ];

            if ($action === AgentTools::DELETE) {
                return $card;
            }

            $values = $arguments;
            unset($values['id']);

            $preview = $this->forms->preview($resource, $record, $values);

            if (! $preview->passes()) {
                return [...$card, 'summary' => $reason];
            }

            foreach ($preview->changes as $name => $change) {
                $field = $blueprint->field($name);

                $card['changes'][] = [
                    'label' => $field->label ?? Str::headline($name),
                    'before' => $record === null ? null : ValueFormatter::format($field, $change['before'], $model),
                    'after' => ValueFormatter::format($field, $change['after'], $model),
                ];
            }

            return $card;
        } catch (Throwable $exception) {
            report($exception);

            return ['title' => $this->labels->label($blueprint->toolPrefix.'_'.$action), 'record' => null, 'changes' => [], 'summary' => $reason];
        }
    }

    /**
     * An approvable tool the package did not derive from a resource: its own
     * reason, and its arguments with readable names.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function generic(string $tool, ?string $reason, array $arguments): array
    {
        $changes = [];

        foreach ($arguments as $key => $value) {
            $changes[] = ['label' => Str::headline((string) $key), 'before' => null, 'after' => ValueFormatter::format(null, $value, Model::class)];
        }

        return ['title' => $this->labels->label($tool), 'record' => null, 'changes' => $changes, 'summary' => $reason];
    }

    private function record(ResourceBlueprint $blueprint, mixed $id): ?Model
    {
        $resource = $blueprint->resource;

        if (! is_scalar($id) || ! RecordKey::valid(app($resource::getModel()), trim((string) $id))) {
            return null;
        }

        $record = $resource::getEloquentQuery()->whereKey(trim((string) $id))->first();

        return $record !== null && $resource::canView($record) ? $record : null;
    }
}
