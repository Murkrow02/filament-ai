<?php

declare(strict_types=1);

use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Tools\FetchDocument;
use Murkrow\FilamentAi\Agent\Tools\SearchKnowledge;
use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Knowledge\KnowledgeSearch;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedForAgentKnowledge(): TestBook
{
    // One chunk per page, so position filters have something to narrow to.
    config()->set('rag.chunking.target_tokens', 30);
    config()->set('rag.chunking.overlap_tokens', 0);
    config()->set('rag.chunking.min_tokens', 0);

    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo. La delibera venne approvata a maggioranza.',
    ]);
    $book->pages()->create([
        'number' => 8,
        'content' => 'Le mura vennero rinforzate con nuove torri di guardia. I lavori durarono due stagioni intere.',
    ]);

    Rag::ingestSync('books');

    return $book;
}

it('searches the knowledge base and numbers the passages', function (): void {
    seedForAgentKnowledge();

    $output = (new SearchKnowledge(['books']))->handle(new Request(['query' => 'chi convoco il consiglio']));

    expect($output)->toContain('[#1]')->toContain('Cronaca cittadina');
});

it('reaches nothing when no source is allowed', function (): void {
    seedForAgentKnowledge();

    // An empty allow-list must mean "nothing", never "everything".
    $output = (new SearchKnowledge([]))->handle(new Request(['query' => 'chi convoco il consiglio']));

    expect($output)->toBe('No passage in the knowledge base matches that query.');
});

it('does not widen the allow-list when a source is named', function (): void {
    seedForAgentKnowledge();

    expect(KnowledgeSearch::narrow(['books'], 'secret'))->toBe([])
        ->and(KnowledgeSearch::narrow(['books'], 'books'))->toBe(['books'])
        ->and(KnowledgeSearch::narrow(['books'], null))->toBe(['books']);
});

it('reports a missing query as an error the model can read', function (): void {
    expect((new SearchKnowledge(['books']))->handle(new Request(['query' => '  '])))
        ->toBe('Error: The "query" argument is required.');
});

it('only offers a source filter when there are sources to choose from', function (): void {
    $schema = new \Illuminate\JsonSchema\JsonSchemaTypeFactory;

    expect((new SearchKnowledge(['books']))->schema($schema))->toHaveKey('source')
        ->and((new SearchKnowledge([]))->schema($schema))->not->toHaveKey('source');
});

it('reads a document span by its identifier', function (): void {
    $book = seedForAgentKnowledge();

    $output = (new FetchDocument(['books']))->handle(new Request([
        'document_id' => (string) $book->id,
        'position_from' => 8,
    ]));

    expect($output)->toContain('Le mura vennero rinforzate')
        ->not->toContain('Guido Novello');
});

it('does not read a document from a source it may not see', function (): void {
    $book = seedForAgentKnowledge();

    expect((new FetchDocument([]))->handle(new Request(['document_id' => (string) $book->id])))
        ->toStartWith('Error: No indexed document');
});
