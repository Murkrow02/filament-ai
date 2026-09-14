<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Conversation;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedStreamCorpus(): void
{
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo.',
    ]);

    Rag::ingestSync('books');
}

it('streams the answer as server-sent events', function (): void {
    seedStreamCorpus();
    config()->set('rag.answering.stream', true);

    $this->actingAs($this->user());

    $response = $this->post('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?']);

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $body = $response->streamedContent();

    expect($body)->toContain('event: start')
        ->and($body)->toContain('event: delta')
        ->and($body)->toContain('event: done');

    // The terminal frame is what the page renders the citations from.
    preg_match('/event: done\ndata: (.*)\n/', $body, $matches);
    $done = json_decode($matches[1], true);

    expect($done['id'])->not->toBeNull()
        ->and($done['conversation'])->toBe(Conversation::query()->firstOrFail()->uuid)
        ->and($done['passages'])->not->toBeEmpty();
});

it('runs retrieval without calling the model when asked to', function (): void {
    seedStreamCorpus();

    $this->actingAs($this->user());

    $response = $this->postJson('/rag/chat/ask', [
        'question' => 'Chi convoco il consiglio?',
        'retrieval_only' => true,
    ])->assertOk();

    expect($response->json('passages'))->not->toBeEmpty()
        ->and($response->json('cost_usd'))->toBeNull()
        ->and(app(\Murkrow\FilamentAi\Contracts\LanguageModel::class)->received())->toBe([]);
});
