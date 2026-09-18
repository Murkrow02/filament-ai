<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Mcp\KnowledgeServer;
use Murkrow\FilamentAi\Mcp\Prompts\GroundedAnswerPrompt;
use Murkrow\FilamentAi\Mcp\Resources\DocumentsResource;
use Murkrow\FilamentAi\Mcp\Tools\AnswerQuestionTool;
use Murkrow\FilamentAi\Mcp\Tools\FetchDocumentTool;
use Murkrow\FilamentAi\Mcp\Tools\SearchKnowledgeTool;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedForMcp(): TestBook
{
    // One chunk per page, so a page-range filter has something to narrow to:
    // with the shipped 512-token target these two short pages land in a single
    // chunk spanning both, and the filter would look broken while being right.
    config()->set('filament-ai.chunking.target_tokens', 30);
    config()->set('filament-ai.chunking.overlap_tokens', 0);
    config()->set('filament-ai.chunking.min_tokens', 0);

    $book = TestBook::create(['title' => 'Cronaca cittadina', 'author' => 'Anonimo']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo. La delibera venne approvata a maggioranza dopo lunga discussione.',
    ]);
    $book->pages()->create([
        'number' => 8,
        'content' => 'Le mura vennero rinforzate con nuove torri di guardia. I lavori durarono due stagioni intere e costarono assai.',
    ]);

    FilamentAi::ingestSync('books');

    return $book;
}

it('exposes the search tool and returns cited passages', function (): void {
    seedForMcp();

    KnowledgeServer::tool(SearchKnowledgeTool::class, ['query' => 'chi convoco il consiglio'])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Cronaca cittadina')
        ->assertSee('[#1]');
});

it('rejects a search with no query', function (): void {
    seedForMcp();

    KnowledgeServer::tool(SearchKnowledgeTool::class, ['query' => '  '])
        ->assertHasErrors();
});

it('says so plainly when nothing matches', function (): void {
    seedForMcp();

    KnowledgeServer::tool(SearchKnowledgeTool::class, [
        'query' => 'qualunque cosa',
        'min_score' => 0.999,
    ])->assertOk()->assertSee('No passage');
});

it('scopes a search to a page range', function (): void {
    seedForMcp();

    KnowledgeServer::tool(SearchKnowledgeTool::class, [
        'query' => 'mura e torri',
        'position_from' => 8,
        'position_to' => 8,
    ])->assertOk()->assertSee('Page 8')->assertDontSee('Pages 6-7');
});

it('names the search tool from config so a host can rename it', function (): void {
    config()->set('filament-ai.mcp.tools.search.name', 'search_books_knowledge');

    expect((new SearchKnowledgeTool)->name())->toBe('search_books_knowledge');
});

it('lets a tool be switched off entirely', function (): void {
    config()->set('filament-ai.mcp.tools.answer.enabled', false);

    expect((new AnswerQuestionTool)->shouldRegister())->toBeFalse()
        ->and((new SearchKnowledgeTool)->shouldRegister())->toBeTrue();
});

it('reads a contiguous span of a document', function (): void {
    $book = seedForMcp();

    KnowledgeServer::tool(FetchDocumentTool::class, [
        'document_id' => (string) $book->id,
        'position_from' => 7,
        'position_to' => 7,
    ])
        ->assertOk()
        ->assertSee('Cronaca cittadina')
        ->assertSee('Guido Novello');
});

it('reports an unknown document instead of returning nothing', function (): void {
    seedForMcp();

    KnowledgeServer::tool(FetchDocumentTool::class, ['document_id' => '99999'])
        ->assertHasErrors();
});

it('answers a question with its sources listed', function (): void {
    seedForMcp();

    KnowledgeServer::tool(AnswerQuestionTool::class, ['question' => 'chi convoco il consiglio?'])
        ->assertOk()
        ->assertSee('Sources:')
        ->assertSee('document_id');
});

it('lists the indexed documents so a client can discover identifiers', function (): void {
    $book = seedForMcp();

    KnowledgeServer::resource(DocumentsResource::class)
        ->assertOk()
        ->assertSee('Cronaca cittadina')
        ->assertSee((string) $book->id)
        ->assertSee('"coverage"');
});

it('offers a grounded-answer prompt naming the configured tools', function (): void {
    config()->set('filament-ai.mcp.tools.search.name', 'search_books_knowledge');

    KnowledgeServer::prompt(GroundedAnswerPrompt::class, ['question' => 'chi era il podesta?'])
        ->assertOk()
        ->assertSee('search_books_knowledge')
        ->assertSee('chi era il podesta?');
});

it('hides sources that config does not expose to mcp', function (): void {
    seedForMcp();

    config()->set('filament-ai.mcp.sources', ['something-else']);

    KnowledgeServer::tool(SearchKnowledgeTool::class, ['query' => 'consiglio'])
        ->assertOk()
        ->assertSee('No passage');
});
