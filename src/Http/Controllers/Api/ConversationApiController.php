<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Chat\ChatPayload;

/**
 * A token client's own conversations, and what it needs to start one.
 *
 * Every shape is the one the browser chat already uses (`ChatPayload`), so a
 * conversation reads the same in both and the abilities hold in both.
 */
class ConversationApiController
{
    public function __construct(
        private readonly ChatPayload $payload,
        private readonly AssistantTurn $turn,
    ) {}

    /**
     * Abilities, suggestions, dictation settings: read once, before the
     * first question.
     */
    public function config(Request $request): JsonResponse
    {
        return response()->json($this->payload->client($request->user()));
    }

    /**
     * The user's own threads, newest first. Empty without the `history`
     * ability, as the page's sidebar is.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        return response()->json([
            'data' => ChatAbilities::allows('history', $user) && $this->turn->available()
                ? $this->payload->conversations($user, isset($data['limit']) ? (int) $data['limit'] : null)
                : [],
        ]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->owned($request, $conversation);

        return response()->json($this->payload->conversation($owned, ChatAbilities::allows('debug', $request->user())));
    }

    public function update(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->owned($request, $conversation);

        abort_unless(ChatAbilities::allows('delete', $request->user()), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
        ]);

        return response()->json([
            'uuid' => $owned,
            'title' => $this->turn->rename($owned, $request->user(), $data['title']),
        ]);
    }

    public function destroy(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->owned($request, $conversation);

        abort_unless(ChatAbilities::allows('delete', $request->user()), 403);

        return response()->json(['deleted' => $this->turn->delete($owned, $request->user())]);
    }

    /**
     * A conversation this user owns, or a 404. A thread that is not theirs and
     * a thread that never existed are the same answer on purpose.
     */
    private function owned(Request $request, string $conversation): string
    {
        abort_unless($this->turn->available(), 404);

        $owned = $this->turn->ownedConversation($conversation, $request->user());

        abort_if($owned === null, 404);

        return $owned;
    }
}
