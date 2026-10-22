<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Tools\Concerns\GuardsToolFailures;
use Murkrow\FilamentAi\Agent\Tools\Contracts\ConditionalTool;
use Murkrow\FilamentAi\Agent\Tools\Contracts\HasChatLabel;

/**
 * A base for an application's own read tools, added through
 * `PanelAssistant::additionalTools()`.
 *
 * It does what every tool here must: never throw (`GuardsToolFailures`),
 * answer a refusal as "Error: ..." the model can explain (`ToolRefusal`),
 * encode a structured result as JSON, and name itself in the chat. A tool
 * returns false from `available()` to be left out for the current user.
 *
 *     final class StockLevels extends AgentTool
 *     {
 *         public function description(): string { return 'Pieces in stock of an item, by size.'; }
 *         public function schema(JsonSchema $schema): array { return ['item' => $schema->string()->required()]; }
 *         public function chatLabel(): string { return 'Controllo le giacenze'; }
 *
 *         protected function run(array $arguments): array|string
 *         {
 *             $item = Item::search($arguments['item']) ?? throw new ToolRefusal('No item matches.');
 *             return ['item' => $item->name, 'stock' => ...];
 *         }
 *     }
 */
abstract class AgentTool implements ConditionalTool, HasChatLabel, Tool
{
    use GuardsToolFailures;

    /**
     * The tool's name as the model calls it: the class name in snake case
     * unless overridden ("StockLevels" => "stock_levels").
     */
    public function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public function chatLabel(): string
    {
        return Str::of($this->name())->replace(['_', '-'], ' ')->ucfirst()->toString();
    }

    public function available(): bool
    {
        return true;
    }

    public function handle(Request $request): string
    {
        return $this->guarded(function () use ($request): string {
            if (! $this->available()) {
                return 'Error: the current user is not allowed to do this.';
            }

            try {
                return $this->encode($this->run($request->all()));
            } catch (ToolRefusal $refusal) {
                return 'Error: '.$refusal->getMessage();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<mixed>|string
     */
    abstract protected function run(array $arguments): array|string;

    /**
     * @param  array<mixed>|string  $result
     */
    protected function encode(array|string $result): string
    {
        return is_string($result)
            ? $result
            : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
