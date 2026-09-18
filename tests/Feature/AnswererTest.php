<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Llm\FakeLanguageModel;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Models\QueryCitation;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedCorpus(): void
{
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo. La delibera fu approvata a maggioranza.',
    ]);

    FilamentAi::ingestSync('books');
}

it('answers from the corpus and records the citations it used', function (): void {
    seedCorpus();

    $result = app(Answerer::class)->answer('Chi convoco il consiglio?');

    expect($result->refused)->toBeFalse()
        ->and($result->answer)->not->toBeEmpty()
        ->and($result->citations)->not->toBeEmpty()
        ->and($result->usedCitations())->not->toBeEmpty();

    $query = QueryLog::query()->firstOrFail();

    expect($query->question)->toBe('Chi convoco il consiglio?')
        ->and($query->refused)->toBeFalse()
        ->and(QueryCitation::query()->where('query_id', $query->id)->count())->toBe($result->citations->count());
});

it('labels citations with the document title and its page', function (): void {
    seedCorpus();

    $result = app(Answerer::class)->answer('Chi convoco il consiglio?');

    expect($result->citations->first()->label)->toContain('Cronaca cittadina')
        ->and($result->citations->first()->label)->toContain('Page 7');
});

it('refuses instead of inventing when retrieval comes back empty', function (): void {
    seedCorpus();

    $result = app(Answerer::class)->answer('Quale e la capitale del Giappone?', new AnswerOptions(
        retrieval: new RetrievalOptions(minScore: 0.999),
    ));

    expect($result->refused)->toBeTrue()
        ->and($result->model)->toBeNull()
        ->and($result->citations)->toBeEmpty();
});

it('never calls the model when there is no context', function (): void {
    seedCorpus();

    $fake = new FakeLanguageModel;
    app()->instance(LanguageModel::class, $fake);

    app()->forgetInstance(Answerer::class);

    app(Answerer::class)->answer('Nulla di pertinente', new AnswerOptions(
        retrieval: new RetrievalOptions(minScore: 0.999),
    ));

    expect($fake->received())->toBeEmpty();
});

it('treats an uncited answer as ungrounded, even after the retry', function (): void {
    seedCorpus();

    $fake = (new FakeLanguageModel)->respondWith(
        'Una risposta sicura di se ma senza fonti.',
        'E anche il tentativo successivo, ancora senza fonti.',
    );
    app()->instance(LanguageModel::class, $fake);
    app()->forgetInstance(Answerer::class);

    $result = app(Answerer::class)->answer('Chi convoco il consiglio?');

    expect($result->refused)->toBeTrue()
        ->and($fake->received())->toHaveCount(2);
});

it('retries once when the first answer omits its citation markers', function (): void {
    seedCorpus();

    $fake = (new FakeLanguageModel)->respondWith(
        'Guido Novello, senza pero citare la fonte.',
        'Guido Novello ha convocato il consiglio [#1].',
    );
    app()->instance(LanguageModel::class, $fake);
    app()->forgetInstance(Answerer::class);

    $result = app(Answerer::class)->answer('Chi convoco il consiglio?');

    expect($result->refused)->toBeFalse()
        ->and($result->answer)->toContain('Guido Novello ha convocato il consiglio')
        ->and($fake->received())->toHaveCount(2)
        ->and($fake->received()[1]['user'])->toContain('did not include any')
        ->and($result->usage->promptTokens)->toBeGreaterThan(0);
});

it('removes markers that point at no source', function (): void {
    seedCorpus();

    $fake = (new FakeLanguageModel)->respondWith('Vero [#1], e anche questo [#42].');
    app()->instance(LanguageModel::class, $fake);
    app()->forgetInstance(Answerer::class);

    $result = app(Answerer::class)->answer('Chi convoco il consiglio?');

    expect($result->answer)->toContain('[#1]')
        ->and($result->answer)->not->toContain('[#42]');
});

it('streams the same answer it would have returned', function (): void {
    seedCorpus();

    $stream = app(Answerer::class)->stream('Chi convoco il consiglio?');

    $streamed = '';

    foreach ($stream as $delta) {
        $streamed .= $delta;
    }

    expect(trim($streamed))->toBe($stream->getReturn()->answer);
});

it('records the channel a question came through', function (): void {
    seedCorpus();

    app(Answerer::class)->answer('Chi convoco il consiglio?', new AnswerOptions(
        channel: QueryChannel::Mcp,
    ));

    expect(QueryLog::query()->firstOrFail()->channel)->toBe(QueryChannel::Mcp);
});

it('can answer without writing to the query log', function (): void {
    seedCorpus();

    app(Answerer::class)->answer('Chi convoco il consiglio?', new AnswerOptions(log: false));

    expect(QueryLog::query()->count())->toBe(0);
});
