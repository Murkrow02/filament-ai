<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Murkrow\FilamentAi\Agent\Tools\AgentTool;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/**
 * A host tool that hands the user a file.
 */
final class ExportBooks extends AgentTool
{
    public function description(): string
    {
        return 'Exports the books as CSV.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $arguments): array|string
    {
        $csv = "title\n".TestBook::query()->orderBy('id')->pluck('title')->implode("\n")."\n";

        return ['files' => [$this->file($csv, 'libri.csv')], 'rows' => TestBook::query()->count()];
    }
}
