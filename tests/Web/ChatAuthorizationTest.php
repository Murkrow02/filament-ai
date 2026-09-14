<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Facades\Rag;
use Murkrow\FilamentAi\Models\Conversation;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

function seedAuthCorpus(): void
{
    $book = TestBook::create(['title' => 'Cronaca cittadina']);

    $book->pages()->create([
        'number' => 7,
        'content' => 'Il podesta Guido Novello convoco il consiglio generale nel mese di marzo.',
    ]);

    Rag::ingestSync('books');
}

it('ignores retrieval settings the caller is not allowed to change', function (): void {
    seedAuthCorpus();

    config()->set('rag.chat.abilities.advanced', false);
    config()->set('rag.retrieval.top_k', 3);

    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', [
        'question' => 'Chi convoco il consiglio?',
        'top_k' => 30,
        'min_score' => 0.99,
    ])->assertOk();

    $query = QueryLog::query()->firstOrFail();

    // The posted values never reached RetrievalOptions: min_score 0.99 would
    // have emptied the result set, and top_k would have been recorded as 30.
    expect($query->min_score)->toBeNull()
        ->and($query->refused)->toBeFalse();
});

it('ignores a model the caller is not allowed to pick', function (): void {
    seedAuthCorpus();

    config()->set('rag.llm.available_models', ['other-model' => 'Other']);
    config()->set('rag.chat.abilities.model', false);

    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', [
        'question' => 'Chi convoco il consiglio?',
        'model' => 'other-model',
    ])->assertOk();

    expect(QueryLog::query()->firstOrFail()->llm_model)->not->toBe('other-model');
});

it('rejects a model that is not on the list', function (): void {
    seedAuthCorpus();

    config()->set('rag.llm.available_models', ['allowed' => 'Allowed']);

    $this->actingAs($this->user());

    $this->postJson('/rag/chat/ask', [
        'question' => 'Chi convoco il consiglio?',
        'model' => 'something-else',
    ])->assertStatus(422);
});

it('withholds cost and passages when those abilities are denied', function (): void {
    seedAuthCorpus();

    config()->set('rag.chat.abilities.cost', false);
    config()->set('rag.chat.abilities.passages', false);

    $this->actingAs($this->user());

    $response = $this->postJson('/rag/chat/ask', ['question' => 'Chi convoco il consiglio?'])->assertOk();

    expect($response->json('cost_usd'))->toBeNull()
        ->and($response->json('tokens'))->toBeNull()
        ->and($response->json('passages'))->toBe([]);
});

it('hides another user\'s conversation', function (): void {
    $other = Conversation::query()->create(['user_id' => '999', 'title' => 'Someone else']);

    $this->actingAs($this->user());

    $this->getJson('/rag/chat/c/'.$other->uuid.'/messages')->assertNotFound();
    $this->deleteJson('/rag/chat/c/'.$other->uuid)->assertNotFound();
    $this->patchJson('/rag/chat/c/'.$other->uuid, ['title' => 'Mine now'])->assertNotFound();
});

it('shows another user\'s conversation when all_conversations is granted', function (): void {
    config()->set('rag.chat.abilities.all_conversations', true);

    $other = Conversation::query()->create(['user_id' => '999', 'title' => 'Someone else']);

    $this->actingAs($this->user());

    $this->getJson('/rag/chat/c/'.$other->uuid.'/messages')->assertOk();
});

it('resolves an ability from a permission name', function (): void {
    config()->set('rag.chat.abilities.cost', 'see rag costs');

    // The stand-in user model has no can() opinion of its own, so the string
    // shape resolves through the Gate and comes back denied rather than
    // exploding -- which is what a host without that permission should see.
    expect(ChatAbilities::allows('cost', $this->user()))->toBeFalse();
});

it('resolves an ability from a closure', function (): void {
    config()->set('rag.chat.abilities.cost', fn ($user): bool => $user !== null);

    expect(ChatAbilities::allows('cost', $this->user()))->toBeTrue()
        ->and(ChatAbilities::allows('cost', null))->toBeFalse();
});

it('lets a host Gate definition win over config', function (): void {
    config()->set('rag.chat.abilities.cost', true);

    Gate::define('rag.chat.cost', fn (): bool => false);

    expect(ChatAbilities::allows('cost', $this->user()))->toBeFalse();
});
