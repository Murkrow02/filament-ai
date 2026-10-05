<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Models\Conversation;
use Murkrow\FilamentAi\Models\ChatFolder;
use Murkrow\FilamentAi\Support\Tables;
use Throwable;

/**
 * The folders a person sorts their assistant chats into.
 *
 * Every read and write is scoped to the owner, recorded the way laravel/ai
 * records a conversation's participant, and a chat can be filed only by the
 * person it belongs to (`ConversationTranscript::owns()`), so neither a folder
 * nor a filing can reach somebody else's. A chat is in one folder or in none.
 */
final class ConversationFolders
{
    /** Enough for any person's sidebar, and a ceiling on a runaway client. */
    public const MAX_FOLDERS = 100;

    public function __construct(
        private readonly ConversationTranscript $transcript,
    ) {}

    /**
     * The tables come from a migration a host may not have run yet.
     */
    public function available(): bool
    {
        try {
            $schema = Schema::connection(Tables::connection());

            return $schema->hasTable(Tables::chatFolders()) && $schema->hasTable(Tables::chatFolderItems());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function folders(object $user): array
    {
        return $this->owned($user)
            ->orderBy('position')->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (ChatFolder $folder): array => ['id' => (int) $folder->id, 'name' => (string) $folder->name])
            ->all();
    }

    /**
     * A new folder, last in the list; null once the user has as many as allowed.
     */
    public function create(object $user, string $name): ?ChatFolder
    {
        if ($this->owned($user)->count() >= self::MAX_FOLDERS) {
            return null;
        }

        return ChatFolder::query()->create([
            'owner_type' => Conversation::participantType($user),
            'owner_id' => (string) Conversation::participantKey($user),
            'name' => self::clean($name),
            'position' => (int) $this->owned($user)->max('position') + 1,
        ]);
    }

    public function rename(object $user, int $folderId, string $name): ?ChatFolder
    {
        $folder = $this->owned($user)->whereKey($folderId)->first();

        $folder?->forceFill(['name' => self::clean($name)])->save();

        return $folder;
    }

    /**
     * Delete a folder. Its chats are not deleted: they go back out of any folder.
     */
    public function delete(object $user, int $folderId): bool
    {
        $folder = $this->owned($user)->whereKey($folderId)->first();

        if ($folder === null) {
            return false;
        }

        $this->items()->where('folder_id', $folder->id)->delete();

        return (bool) $folder->delete();
    }

    /**
     * File a chat the user owns into one of their folders, or take it out of
     * any with null. False when the chat or the folder is not theirs.
     */
    public function move(object $user, string $conversationId, ?int $folderId): bool
    {
        if (! $this->transcript->owns($conversationId, $user)) {
            return false;
        }

        if ($folderId === null) {
            $this->forget($conversationId);

            return true;
        }

        if (! $this->owned($user)->whereKey($folderId)->exists()) {
            return false;
        }

        $this->items()->updateOrInsert(
            ['conversation_id' => $conversationId],
            ['folder_id' => $folderId, 'created_at' => now(), 'updated_at' => now()],
        );

        return true;
    }

    /**
     * A chat that is gone leaves no filing behind.
     */
    public function forget(string $conversationId): void
    {
        $this->items()->where('conversation_id', $conversationId)->delete();
    }

    /**
     * Which folder each of the user's filed chats is in.
     *
     * @return array<string, int> conversation id => folder id
     */
    public function filed(object $user): array
    {
        $folders = $this->owned($user)->pluck('id')->all();

        if ($folders === []) {
            return [];
        }

        return $this->items()
            ->whereIn('folder_id', $folders)
            ->pluck('folder_id', 'conversation_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return Builder<ChatFolder>
     */
    private function owned(object $user): Builder
    {
        return ChatFolder::query()
            ->where('owner_type', Conversation::participantType($user))
            ->where('owner_id', (string) Conversation::participantKey($user));
    }

    private function items(): QueryBuilder
    {
        return DB::connection(Tables::connection())->table(Tables::chatFolderItems());
    }

    private static function clean(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return mb_substr($name, 0, 100);
    }
}
