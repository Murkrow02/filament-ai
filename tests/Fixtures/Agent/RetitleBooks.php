<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures\Agent;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Murkrow\FilamentAi\Agent\Chat\ApprovalCard;
use Murkrow\FilamentAi\Agent\Tools\AgentAction;
use Murkrow\FilamentAi\Agent\Tools\ToolRefusal;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/**
 * A host write tool: renames every book of an author in one change.
 */
final class RetitleBooks extends AgentAction
{
    public function description(): string
    {
        return 'Prefixes the titles of every book of an author.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['author' => $schema->string()->required(), 'prefix' => $schema->string()->required()];
    }

    public function chatLabel(): string
    {
        return 'Rinomino i libri';
    }

    protected function prepare(array $arguments): ApprovalCard
    {
        $books = TestBook::query()->where('author', $arguments['author'] ?? '')->orderBy('id')->get();

        if ($books->isEmpty()) {
            throw new ToolRefusal('no book by that author.');
        }

        $card = ApprovalCard::make('Rinomina i libri')->record($arguments['author']);

        foreach ($books as $book) {
            $card->change('Titolo', $arguments['prefix'].' '.$book->title, $book->title);
        }

        return $card->warning("{$books->count()} libri cambiano titolo.");
    }

    protected function perform(array $arguments, ApprovalCard $card): array|string
    {
        $books = TestBook::query()->where('author', $arguments['author'])->get();

        foreach ($books as $book) {
            $book->update(['title' => $arguments['prefix'].' '.$book->title]);
        }

        return ['renamed' => $books->count()];
    }
}
