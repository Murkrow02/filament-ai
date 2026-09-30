<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Murkrow\FilamentAi\Events\DocumentIngested;
use Murkrow\FilamentAi\Jobs\RefreshLexicalStatisticsJob;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Contracts\Reranker;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Retrieval\Lexical\TsVectorLexicalSearch;
use Murkrow\FilamentAi\Reranking\RerankerManager;
use Murkrow\FilamentAi\Support\FullText;
use Murkrow\FilamentAi\Support\Tables;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedHybridLibrary(): void
{
    $texts = [
        // Seeded last so it has the highest id: ranking it first has to come
        // from the score, not from the tie-break.
        'Cronaca del porto' => 'Le barche rientrarono al porto dopo la tempesta. I pescatori scaricarono le reti sulla spiaggia della città.',
        'Guida della città' => 'La città conserva le antiche mura e una cattedrale dedicata al santo patrono.',
        'Il conto di Giovanni' => 'Le sorelle dissero a Giovanni di andare da Nicola il beccaio e farsi dare mezzo rotolo di trippa.',
    ];

    foreach ($texts as $title => $content) {
        TestBook::create(['title' => $title])->pages()->create(['number' => 1, 'content' => $content]);
    }

    FilamentAi::ingestSync('books');
}

function titles($result): array
{
    return $result->chunks->pluck('documentTitle')->all();
}

it('stores an indexed tsvector column', function (): void {
    $index = DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexdef ILIKE ?',
        [Tables::chunks(), '%USING gin%'],
    );

    expect(FullText::installed(DB::connection()))->toBeTrue()
        ->and($index->indexdef)->toContain(FullText::COLUMN);
});

it('finds a chunk that matches only some of the words, rarest first', function (): void {
    seedHybridLibrary();

    $ids = (new TsVectorLexicalSearch)->candidates('la città del beccaio', new VectorQuery(vector: []), 10);
    $first = DB::table(Tables::chunks())->where('id', $ids[0])->value('content');

    expect($ids)->toHaveCount(3)
        ->and($first)->toContain('beccaio');
});

it('ranks the same from the word statistics as from live counts', function (): void {
    seedHybridLibrary();

    $live = (new TsVectorLexicalSearch)->candidates('la città del beccaio', new VectorQuery(vector: []), 10);

    $words = FullText::refreshStatistics(DB::connection());
    $stored = (new TsVectorLexicalSearch)->candidates('la città del beccaio', new VectorQuery(vector: []), 10);

    expect($words)->toBeGreaterThan(10)
        ->and(DB::table(Tables::lexemes())->where('word', 'beccai')->value('ndoc'))->toBe(1)
        ->and($stored)->toBe($live);
});

it('queues one word recount per import and the recount picks up new words', function (): void {
    seedHybridLibrary();

    // Ingestion runs inside the test's transaction, where a queued job waits
    // for a commit that never comes; call the listener directly instead.
    Queue::fake();
    $document = Document::query()->firstOrFail();

    foreach ([1, 0, 2] as $created) {
        RefreshLexicalStatisticsJob::afterIngestion(new DocumentIngested($document, $created, 0, 0));
    }

    expect(Event::hasListeners(DocumentIngested::class))->toBeTrue();
    Queue::assertPushed(RefreshLexicalStatisticsJob::class, 1);

    (new RefreshLexicalStatisticsJob)->handle();

    expect(DB::table(Tables::lexemes())->where('word', 'beccai')->value('ndoc'))->toBe(1);
});

it('folds accents on both sides', function (): void {
    seedHybridLibrary();

    $ids = (new TsVectorLexicalSearch)->candidates('citta', new VectorQuery(vector: []), 10);

    expect($ids)->toHaveCount(2);
});

it('splits elided articles from the word they carry', function (): void {
    expect(TsVectorLexicalSearch::terms("Cosa ordinò dell'ingenuo fanciullo l’ortolano?"))
        ->toBe(['cosa', 'ordinò', 'ingenuo', 'fanciullo', 'ortolano']);
});

it('surfaces a lexical-only hit that the vector score floor would have dropped', function (): void {
    seedHybridLibrary();

    // Fake embeddings are not semantic: nothing clears a 0.99 floor by vector.
    $vectorOnly = app(Retriever::class)->retrieve('beccaio trippa', new RetrievalOptions(minScore: 0.99, hybridDriver: 'null'));
    $hybrid = app(Retriever::class)->retrieve('beccaio trippa', new RetrievalOptions(minScore: 0.99, hybridDriver: 'tsvector'));

    expect($vectorOnly->isEmpty())->toBeTrue()
        ->and(titles($hybrid))->toBe(['Il conto di Giovanni']);
});

it('respects filters on the lexical leg', function (): void {
    seedHybridLibrary();

    $result = app(Retriever::class)->retrieve('beccaio porto', new RetrievalOptions(
        externalIds: [(string) TestBook::query()->where('title', 'Cronaca del porto')->value('id')],
        minScore: 0.99,
        hybridDriver: 'tsvector',
    ));

    expect(titles($result))->toBe(['Cronaca del porto']);
});

it('searches the rewrites of an expanded question too', function (): void {
    seedHybridLibrary();

    app(LanguageModel::class)->respondWith('{"queries": ["Nicola il beccaio e la trippa"]}');

    $result = app(Retriever::class)->retrieve(
        "cosa comprò l'ingenuo fanciullo dal macellaio",
        new RetrievalOptions(minScore: 0.99, hybridDriver: 'tsvector', expand: true),
    );

    expect(titles($result))->toContain('Il conto di Giovanni');
});

it('orders by the reranker and keeps the fused order when it fails', function (): void {
    seedHybridLibrary();

    $manager = app(RerankerManager::class);

    $manager->register('prefers-sea', fn () => new class implements Reranker
    {
        public function score(string $question, array $passages): array
        {
            return array_map(static fn (string $p): float => str_contains($p, 'barche') ? 0.9 : 0.1, $passages);
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });

    $manager->register('broken', fn () => new class implements Reranker
    {
        public function score(string $question, array $passages): array
        {
            throw new RuntimeException('down');
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });

    // Grades nothing apart: every passage 0.
    $manager->register('flat', fn () => new class implements Reranker
    {
        public function score(string $question, array $passages): array
        {
            return array_fill(0, count($passages), 0.0);
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });

    $options = fn (string $driver) => new RetrievalOptions(minScore: 0.0, hybridDriver: 'tsvector', rerankDriver: $driver, mmr: false);

    $reranked = app(Retriever::class)->retrieve('beccaio', $options('prefers-sea'));
    $fallback = app(Retriever::class)->retrieve('beccaio', $options('broken'));
    $flat = app(Retriever::class)->retrieve('beccaio', $options('flat'));

    expect(titles($reranked)[0])->toBe('Cronaca del porto')
        ->and($reranked->chunks->first()->score)->toBe(0.9)
        ->and(titles($fallback)[0])->toBe('Il conto di Giovanni')
        ->and(titles($flat)[0])->toBe('Il conto di Giovanni')
        ->and($flat->chunks->first()->score)->not->toBe(0.0);
});
