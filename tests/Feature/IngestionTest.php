<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Enums\DocumentStatus;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Enums\RunStatus;
use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function pagesOfProse(int $count, int $sentences = 8): array
{
    $pages = [];

    for ($page = 1; $page <= $count; $page++) {
        $text = [];

        for ($i = 1; $i <= $sentences; $i++) {
            $text[] = "Pagina {$page} frase {$i}: il consiglio delibero in merito alla questione sollevata.";
        }

        $pages[] = implode(' ', $text);
    }

    return $pages;
}

function seedBook(string $title = 'Cronaca', int $pages = 3): TestBook
{
    $book = TestBook::create(['title' => $title]);

    foreach (pagesOfProse($pages) as $index => $content) {
        $book->pages()->create(['number' => $index + 1, 'content' => $content]);
    }

    return $book;
}

it('runs an ingestion end to end and embeds every chunk', function (): void {
    seedBook();

    $run = Rag::ingestSync('books');

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->documents_done)->toBe(1)
        ->and($run->chunks_created)->toBeGreaterThan(0)
        ->and($run->chunks_embedded)->toBe($run->chunks_total);

    $document = Document::query()->firstOrFail();

    expect($document->segment_count)->toBe(3)
        ->and($document->chunk_count)->toBeGreaterThan(0)
        ->and($document->embedded_chunk_count)->toBe($document->chunk_count)
        ->and($document->status)->toBe(DocumentStatus::Embedded)
        ->and($document->content_checksum)->not->toBeNull();

    expect(Chunk::query()->whereNull('embedded_at')->count())->toBe(0);
});

it('records the page range each chunk came from', function (): void {
    seedBook(pages: 4);

    Rag::ingestSync('books');

    $chunks = Chunk::query()->orderBy('ordinal')->get();

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk->position_end)->toBeGreaterThanOrEqual($chunk->position_start)
            ->and($chunk->position_start)->toBeGreaterThanOrEqual(1)
            ->and($chunk->position_end)->toBeLessThanOrEqual(4);
    }

    expect($chunks->contains(fn (Chunk $c): bool => $c->position_end > $c->position_start))->toBeTrue();
});

it('skips unchanged documents on an incremental re-run', function (): void {
    seedBook();

    Rag::ingestSync('books');
    $second = Rag::ingestSync('books', mode: IngestionMode::Incremental);

    expect($second->documents_skipped)->toBe(1)
        ->and($second->documents_done)->toBe(0)
        ->and($second->chunks_created)->toBe(0);
});

it('reuses the vectors of text that did not change', function (): void {
    // Enough pages to fill several windows: a document that fits in one chunk
    // has nothing to reuse, which would make this test pass for the wrong
    // reason.
    $book = seedBook(pages: 8);

    Rag::ingestSync('books');

    $before = Chunk::query()->orderBy('ordinal')->get();
    $untouched = $before->first();
    $untouchedEmbeddedAt = $untouched->embedded_at;

    // Rewrite the last page. The window slides over a continuous sentence
    // stream, so an edit near the start legitimately re-cuts every chunk after
    // it; what must be reused is the text the edit did not reach.
    $book->pages()->where('number', 8)->update([
        'content' => 'Una pagina finale completamente diversa, con parole nuove e nessun riferimento al passato.',
    ]);

    $run = Rag::ingestSync('books', mode: IngestionMode::Full);

    expect($run->chunks_reused)->toBeGreaterThan(0)
        ->and($run->chunks_created)->toBeGreaterThan(0)
        ->and($run->chunks_created)->toBeLessThan($before->count());

    // A chunk whose text never changed must keep its original vector rather
    // than being re-embedded at cost.
    $after = Chunk::query()->where('content_hash', $untouched->content_hash)->first();

    expect($after)->not->toBeNull()
        ->and($after->embedded_at->timestamp)->toBe($untouchedEmbeddedAt->timestamp);
});

it('deletes chunks whose source text disappeared', function (): void {
    $book = seedBook(pages: 4);

    Rag::ingestSync('books');
    $before = Chunk::query()->count();

    $book->pages()->where('number', '>', 2)->delete();

    $run = Rag::ingestSync('books', mode: IngestionMode::Full);

    expect($run->chunks_deleted)->toBeGreaterThan(0)
        ->and(Chunk::query()->count())->toBeLessThan($before);
});

it('honours a filter when selecting documents', function (): void {
    $first = seedBook('Primo');
    seedBook('Secondo');

    $run = Rag::ingestSync('books', ['ids' => (string) $first->id]);

    expect($run->documents_total)->toBe(1)
        ->and(Document::query()->count())->toBe(1)
        ->and(Document::query()->first()->title)->toBe('Primo');
});

it('estimates work before anything is queued', function (): void {
    seedBook(pages: 5);

    $estimate = Rag::estimate('books');

    expect($estimate->documents)->toBe(1)
        ->and($estimate->chunks)->toBeGreaterThan(0)
        ->and($estimate->tokens)->toBeGreaterThan(0);
});
