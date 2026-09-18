<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Chat\ChatPayload;

/**
 * The chat page and everything around a conversation except asking.
 *
 * Conversations belong to laravel/ai's store -- the assistant's memory and the
 * only place an approval pause can resume from -- so renaming and deleting go
 * through the transcript rather than through a model of this package's own.
 */
class ChatController
{
    public function __construct(
        private readonly ChatPayload $payload,
        private readonly AssistantTurn $turn,
    ) {}

    public function index(Request $request): View
    {
        return $this->page($request, null);
    }

    public function show(Request $request, string $conversation): View
    {
        return $this->page($request, $conversation);
    }

    /**
     * The turns of one conversation, for switching threads without a reload.
     */
    public function messages(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->owned($request, $conversation);

        return response()->json($this->payload->conversation($owned));
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

    private function page(Request $request, ?string $conversation): View
    {
        // The page, unlike the endpoints behind it, belongs to the standalone
        // chat alone: switching that off leaves the panel's chat working.
        abort_unless(config('rag.chat.enabled', true), 404);

        $data = $this->payload->build($request->user(), [
            'conversation' => $conversation,
            'resource' => $request->string('resource')->toString() ?: null,
            'record' => $request->string('record')->toString() ?: null,
        ]);

        return view('rag::chat.index', [
            'payload' => $data,
            'abilities' => $data['abilities'],
            'layout' => (string) config('rag.chat.layout', 'rag::chat.layout'),
        ]);
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
