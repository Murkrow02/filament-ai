<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Concerns;

use Illuminate\Http\Request;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Models\Conversation;

trait InteractsWithConversations
{
    /**
     * A conversation somebody else owns is reported as missing, not as
     * forbidden: 403 would confirm that it exists.
     */
    protected function authorizeConversation(Request $request, Conversation $conversation): void
    {
        if (ChatAbilities::allows('all_conversations', $request->user())) {
            return;
        }

        $owner = $request->user()?->getAuthIdentifier();
        $owner = $owner === null ? null : (string) $owner;

        abort_unless($conversation->user_id === $owner, 404);
    }

    protected function newConversation(Request $request): Conversation
    {
        $userId = $request->user()?->getAuthIdentifier();

        $conversation = Conversation::query()->create([
            'user_id' => $userId === null ? null : (string) $userId,
            'title' => null,
            'settings' => [],
        ]);

        $this->pruneConversations($request);

        return $conversation;
    }

    /**
     * Keep the sidebar finite.
     *
     * Deleting cascades to the query rows, which is the point: an unbounded
     * chat history is an unbounded copy of every answer the corpus ever gave.
     */
    protected function pruneConversations(Request $request): void
    {
        $keep = (int) config('rag.chat.max_conversations', 200);

        if ($keep < 1) {
            return;
        }

        $userId = $request->user()?->getAuthIdentifier();

        // Deleted one at a time so the model's deleting hook runs and takes
        // the query rows with it.
        Conversation::query()
            ->forUser($userId)
            ->recent()
            ->offset($keep)
            ->limit(100)
            ->get()
            ->each->delete();
    }
}
