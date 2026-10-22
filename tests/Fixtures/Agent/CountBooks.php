<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Murkrow\FilamentAi\Agent\Tools\AgentTool;
use Murkrow\FilamentAi\Agent\Tools\ToolRefusal;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/**
 * A host read tool: counts books by author.
 */
final class CountBooks extends AgentTool
{
    public static bool $available = true;

    public function description(): string
    {
        return 'Counts the books of an author.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['author' => $schema->string()->required()];
    }

    public function chatLabel(): string
    {
        return 'Conto i libri';
    }

    public function available(): bool
    {
        return self::$available;
    }

    protected function run(array $arguments): array|string
    {
        $count = TestBook::query()->where('author', $arguments['author'] ?? '')->count();

        if ($count === 0) {
            throw new ToolRefusal('no book by that author.');
        }

        return ['author' => $arguments['author'], 'books' => $count];
    }
}
