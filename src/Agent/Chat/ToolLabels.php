<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\ResourceBlueprint;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Throwable;

/**
 * What a tool call is called on the page: "Search customers", not
 * `customers_list`.
 *
 * The tool name is an identifier for the model; a person reads the resource's
 * own label and a verb in their language. The raw name is still available to
 * whoever has the `debug` ability.
 */
final class ToolLabels
{
    private const FIXED = ['search_knowledge', 'fetch_document', 'run_code', 'search_web', 'fetch_web_page'];

    /** @var list<ResourceBlueprint>|null */
    private ?array $blueprints = null;

    public function __construct(private readonly ResourceToolRegistry $registry) {}

    public function label(string $tool): string
    {
        if (in_array($tool, self::FIXED, true)) {
            return (string) __('filament-ai::messages.tools.'.$tool);
        }

        [$blueprint, $action] = $this->resourceTool($tool);

        if ($blueprint !== null) {
            return (string) __('filament-ai::messages.tools.'.$action, [
                'label' => $blueprint->label,
                'plural' => $blueprint->pluralLabel,
            ]);
        }

        return Str::of($tool)->replace(['_', '-'], ' ')->ucfirst()->toString();
    }

    /**
     * The resource behind a derived tool, and what the tool does to it.
     *
     * @return array{0: ResourceBlueprint|null, 1: string|null}
     */
    public function resourceTool(string $tool): array
    {
        foreach ($this->blueprints() as $blueprint) {
            foreach (AgentTools::ABILITIES as $ability) {
                if ($tool === $blueprint->toolPrefix.'_'.$ability) {
                    return [$blueprint, $ability];
                }
            }
        }

        return [null, null];
    }

    /**
     * @return list<ResourceBlueprint>
     */
    private function blueprints(): array
    {
        try {
            return $this->blueprints ??= $this->registry->blueprints(Filament::getCurrentOrDefaultPanel());
        } catch (Throwable) {
            return $this->blueprints = [];
        }
    }
}
