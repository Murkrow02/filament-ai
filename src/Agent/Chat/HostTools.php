<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Laravel\Ai\Contracts\Tool;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Throwable;

/**
 * Finds an application's own tool by name, for the parts of the chat that
 * only have the name: the label on a tool step and the approval card of a
 * paused call, live or read back from a stored conversation.
 *
 * The tools come from the configured assistants' `additionalTools()` (the
 * panel's and the token API's), built once per request.
 */
final class HostTools
{
    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    public function find(string $name): ?Tool
    {
        return $this->tools()[$name] ?? null;
    }

    /**
     * @return array<string, Tool>
     */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = [];

        $classes = array_unique(array_filter([
            config('filament-ai.agent.assistant'),
            config('filament-ai.api.assistant'),
        ], static fn (mixed $class): bool => is_string($class) && is_a($class, PanelAssistant::class, true)));

        foreach ($classes as $class) {
            try {
                foreach (app($class)->hostTools() as $tool) {
                    $this->tools[$tool->name()] ??= $tool;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->tools;
    }
}
