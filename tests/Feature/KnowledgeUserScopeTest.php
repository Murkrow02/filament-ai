<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Tools\FetchDocument;
use Murkrow\FilamentAi\Agent\Tools\SearchKnowledge;
use Murkrow\FilamentAi\Facades\FilamentAi;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Murkrow\FilamentAi\Tests\Fixtures\ScopedBookSource;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

beforeEach(function (): void {
    config()->set('filament-ai.sources', [ScopedBookSource::class]);
    app(SourceRegistry::class)->flush();
    ScopedBookSource::$broken = false;

    $this->mine = TestBook::create(['title' => 'Il mio registro']);
    $this->mine->pages()->create(['number' => 1, 'content' => 'Il consiglio approvo la delibera sulle mura.']);

    $this->theirs = TestBook::create(['title' => 'Il registro altrui']);
    $this->theirs->pages()->create(['number' => 1, 'content' => 'Il consiglio respinse la delibera sulle mura.']);

    FilamentAi::ingestSync('books');

    $user = new User;
    $user->forceFill(['id' => 7]);
    $this->actingAs($user);

    ScopedBookSource::$readable = [7 => [(string) $this->mine->id]];
});

it('searches only the documents the signed-in user may read', function (): void {
    $output = (new SearchKnowledge(['books']))->handle(new Request(['query' => 'delibera sulle mura']));

    expect($output)->toContain('Il mio registro')->not->toContain('Il registro altrui');
});

it('will not read a document the user may not see, even by id', function (): void {
    expect((new FetchDocument(['books']))->handle(new Request(['document_id' => (string) $this->theirs->id])))
        ->toStartWith('Error:')
        ->and((new FetchDocument(['books']))->handle(new Request(['document_id' => (string) $this->mine->id])))
        ->toContain('Il mio registro');
});

it('leaves a source out when its scope cannot be built', function (): void {
    ScopedBookSource::$broken = true;

    expect((new SearchKnowledge(['books']))->handle(new Request(['query' => 'delibera sulle mura'])))
        ->toBe('No passage in the knowledge base matches that query.');
});
