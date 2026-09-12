<?php

declare(strict_types=1);

use Murkrow\Rag\Facades\Rag;
use Murkrow\Rag\Jobs\PruneOrphanChunksJob;
use Murkrow\Rag\Models\Chunk;
use Murkrow\Rag\Models\Document;
use Murkrow\Rag\Tests\Fixtures\TestBook;

function seedIndexedBook(string $title = 'Cronaca'): TestBook
{
    $book = TestBook::create(['title' => $title]);

    $book->pages()->create([
        'number' => 1,
        'content' => str_repeat('Il consiglio delibero in merito alla questione sollevata. ', 12),
    ]);

    Rag::ingestSync('books', ['ids' => [$book->getKey()]]);

    return $book;
}

it('forgets one document and its chunks', function (): void {
    $book = seedIndexedBook();
    $other = seedIndexedBook('Memorie');

    $documentId = Document::query()->where('external_id', (string) $book->getKey())->value('id');
    $survivorId = Document::query()->where('external_id', (string) $other->getKey())->value('id');

    expect(Rag::forget('books', $book->getKey()))->toBeTrue();

    expect(Document::query()->whereKey($documentId)->exists())->toBeFalse()
        ->and(Chunk::query()->where('document_id', $documentId)->count())->toBe(0);

    // Only the one asked for: the cascade must not reach the rest of the source.
    expect(Document::query()->whereKey($survivorId)->exists())->toBeTrue()
        ->and(Chunk::query()->where('document_id', $survivorId)->count())->toBeGreaterThan(0);
});

it('reports when nothing was indexed under that external id', function (): void {
    seedIndexedBook();

    expect(Rag::forget('books', 9999))->toBeFalse()
        ->and(Rag::forget('titles', 1))->toBeFalse()
        ->and(Document::query()->count())->toBe(1);
});

it('prunes documents whose host record is gone', function (): void {
    $book = seedIndexedBook();
    $survivor = seedIndexedBook('Memorie');

    // Deleted without going through forget(), which is what the nightly sweep
    // is the safety net for.
    TestBook::query()->whereKey($book->getKey())->delete();

    (new PruneOrphanChunksJob('books'))->handle(Rag::sources());

    expect(Document::query()->pluck('external_id')->all())
        ->toBe([(string) $survivor->getKey()]);
});
