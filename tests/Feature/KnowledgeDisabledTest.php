<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Contracts\VectorStore;
use Murkrow\FilamentAi\Data\VectorQuery;
use Murkrow\FilamentAi\Exceptions\KnowledgeDisabledException;
use Murkrow\FilamentAi\Support\Knowledge;
use Murkrow\FilamentAi\Support\Tables;
use Murkrow\FilamentAi\VectorStores\NullVectorStore;
use Murkrow\FilamentAi\VectorStores\VectorStoreManager;

/**
 * A host with only the panel agent: no pgvector, migrations on any database.
 */
function disableKnowledge(): void
{
    config()->set('filament-ai.knowledge.enabled', false);

    // The suite binds an in-memory store; resolve through the manager as a host does.
    app()->forgetInstance(VectorStore::class);
    app()->singleton(VectorStore::class, fn ($app): VectorStore => $app->make(VectorStoreManager::class)->driver());
    app()->forgetInstance(VectorStoreManager::class);
}

it('is on by default and off with the switch or the null driver', function (): void {
    expect(Knowledge::enabled())->toBeTrue();

    config()->set('filament-ai.vector.driver', 'null');
    expect(Knowledge::enabled())->toBeFalse();

    config()->set('filament-ai.vector.driver', 'memory');
    config()->set('filament-ai.knowledge.enabled', false);
    expect(Knowledge::enabled())->toBeFalse();
});

it('resolves the null vector store when the knowledge base is off', function (): void {
    disableKnowledge();

    expect(app(VectorStore::class))->toBeInstanceOf(NullVectorStore::class);
});

it('finds nothing and refuses to store vectors', function (): void {
    $store = new NullVectorStore;

    $store->assertSupported();

    expect($store->search(new VectorQuery(vector: array_fill(0, 64, 0.1), limit: 5)))->toBeEmpty()
        ->and($store->read([1, 2]))->toBe([])
        ->and($store->countEmbedded())->toBe(0)
        ->and(fn () => $store->upsert([['id' => 1, 'vector' => [0.1]]], 'model', 1))
        ->toThrow(KnowledgeDisabledException::class);
});

it('migrates without a vector column and rolls back cleanly', function (): void {
    disableKnowledge();

    Artisan::call('migrate:fresh', ['--database' => 'testing']);

    expect(Schema::hasTable(Tables::chunks()))->toBeTrue()
        ->and(Schema::hasColumn(Tables::chunks(), 'embedding'))->toBeFalse();

    Artisan::call('migrate:rollback', ['--database' => 'testing', '--step' => 100]);

    expect(Schema::hasTable(Tables::chunks()))->toBeFalse();
});
