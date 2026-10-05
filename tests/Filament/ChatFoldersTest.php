<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Murkrow\FilamentAi\Chat\ChatPayload;
use Murkrow\FilamentAi\Models\ChatFolder;
use Murkrow\FilamentAi\Support\Tables;

/*
 * Folders for the chat's sidebar. The chats are laravel/ai's; which folder
 * each is in is ours, and every folder and filing is its owner's alone.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

function ownThread(string $title = 'Una chat', ?string $updatedAt = null): string
{
    $user = auth()->user();
    $conversation = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => Conversation::participantType($user),
        'participant_id' => Conversation::participantKey($user),
        'title' => $title,
    ]);

    if ($updatedAt !== null) {
        $conversation->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    return (string) $conversation->id;
}

it('creates, renames and deletes a folder, keeping its chats', function (): void {
    $thread = ownThread();

    $folder = $this->postJson('/ai/chat/folders', ['name' => '  Indizio   12 '])
        ->assertCreated()
        ->assertJsonPath('name', 'Indizio 12')
        ->json('id');

    $this->putJson('/ai/chat/c/'.$thread.'/folder', ['folder' => $folder])
        ->assertOk()
        ->assertJsonPath('folder_id', $folder);

    $this->patchJson('/ai/chat/folders/'.$folder, ['name' => 'Indizio 13'])
        ->assertOk()
        ->assertJsonPath('name', 'Indizio 13');

    $this->deleteJson('/ai/chat/folders/'.$folder)->assertOk();

    expect(ChatFolder::query()->count())->toBe(0)
        ->and(DB::table(Tables::chatFolderItems())->count())->toBe(0)
        ->and(Conversation::query()->whereKey($thread)->exists())->toBeTrue();
});

it('moves a chat between folders and out of them', function (): void {
    $thread = ownThread();
    $first = $this->postJson('/ai/chat/folders', ['name' => 'A'])->json('id');
    $second = $this->postJson('/ai/chat/folders', ['name' => 'B'])->json('id');

    $this->putJson('/ai/chat/c/'.$thread.'/folder', ['folder' => $first])->assertOk();
    $this->putJson('/ai/chat/c/'.$thread.'/folder', ['folder' => $second])->assertOk();

    expect(DB::table(Tables::chatFolderItems())->where('conversation_id', $thread)->value('folder_id'))->toBe($second);

    $this->putJson('/ai/chat/c/'.$thread.'/folder', ['folder' => null])->assertOk()->assertJsonPath('folder_id', null);

    expect(DB::table(Tables::chatFolderItems())->count())->toBe(0);
});

it('sends the folders and every filed chat, however old, to the page', function (): void {
    config()->set('filament-ai.agent.chat.history', 1);

    $old = ownThread('Vecchia', now()->subYear()->toDateTimeString());
    ownThread('Nuova');
    $folder = $this->postJson('/ai/chat/folders', ['name' => 'Archivio'])->json('id');
    $this->putJson('/ai/chat/c/'.$old.'/folder', ['folder' => $folder])->assertOk();

    $payload = app(ChatPayload::class)->build(auth()->user());

    expect($payload['abilities']['folders'])->toBeTrue()
        ->and($payload['folders'])->toBe([['id' => $folder, 'name' => 'Archivio']])
        ->and(collect($payload['conversations'])->pluck('title')->all())->toBe(['Nuova', 'Vecchia'])
        ->and(collect($payload['conversations'])->firstWhere('uuid', $old)['folder_id'])->toBe($folder)
        ->and($payload['endpoints'])->toHaveKeys(['folders', 'folder', 'file']);
});

it('forgets the filing when the chat is deleted', function (): void {
    $thread = ownThread();
    $folder = $this->postJson('/ai/chat/folders', ['name' => 'A'])->json('id');
    $this->putJson('/ai/chat/c/'.$thread.'/folder', ['folder' => $folder])->assertOk();

    $this->deleteJson('/ai/chat/c/'.$thread)->assertOk();

    expect(DB::table(Tables::chatFolderItems())->count())->toBe(0)
        ->and(ChatFolder::query()->count())->toBe(1);
});

it('will not touch somebody else\'s folder or chat', function (): void {
    $foreignFolder = ChatFolder::query()->create(['owner_type' => 'App\\Models\\User', 'owner_id' => '999', 'name' => 'Loro']);
    $foreignThread = Conversation::query()->create([
        'id' => (string) Str::uuid7(),
        'participant_type' => 'App\\Models\\User',
        'participant_id' => 999,
        'title' => 'Loro',
    ])->id;
    $mine = $this->postJson('/ai/chat/folders', ['name' => 'Mia'])->json('id');

    $this->patchJson('/ai/chat/folders/'.$foreignFolder->id, ['name' => 'Mia ora'])->assertNotFound();
    $this->deleteJson('/ai/chat/folders/'.$foreignFolder->id)->assertNotFound();
    $this->putJson('/ai/chat/c/'.ownThread().'/folder', ['folder' => $foreignFolder->id])->assertNotFound();
    $this->putJson('/ai/chat/c/'.$foreignThread.'/folder', ['folder' => $mine])->assertNotFound();

    expect($foreignFolder->refresh()->name)->toBe('Loro')
        ->and(DB::table(Tables::chatFolderItems())->count())->toBe(0);
});

it('renders the new-folder button only when folders are allowed', function (): void {
    $this->get('/ai/chat')->assertOk()->assertSee('id="fai-new-folder"', false);

    config()->set('filament-ai.chat.abilities.folders', false);

    $this->get('/ai/chat')->assertOk()->assertDontSee('id="fai-new-folder"', false);
});

it('refuses folders without the folders ability, and hides them from the page', function (): void {
    config()->set('filament-ai.chat.abilities.folders', false);

    $this->postJson('/ai/chat/folders', ['name' => 'No'])->assertForbidden();

    $payload = app(ChatPayload::class)->build(auth()->user());

    expect($payload['abilities']['folders'])->toBeFalse()
        ->and($payload['folders'])->toBe([]);
});
