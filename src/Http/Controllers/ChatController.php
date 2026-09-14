<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Chat\ChatPayload;
use Murkrow\FilamentAi\Http\Concerns\InteractsWithConversations;
use Murkrow\FilamentAi\Models\Conversation;
use Murkrow\FilamentAi\Models\QueryLog;

/**
 * The chat page and everything around a conversation except asking.
 */
class ChatController
{
    use InteractsWithConversations;

    public function __construct(
        private readonly ChatPayload $payload,
    ) {}

    public function index(Request $request): View
    {
        return $this->page($request, null);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->authorizeConversation($request, $conversation);

        return $this->page($request, $conversation);
    }

    /**
     * The turns of one conversation, for switching threads without a reload.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json(
            $this->payload->conversation($conversation, ChatAbilities::allowed($request->user()))
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->payload->persists(ChatAbilities::allowed($request->user())), 403);

        return response()->json($this->payload->summary($this->newConversation($request)), 201);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        abort_unless(ChatAbilities::allows('delete', $request->user()), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        $conversation->fill($data)->save();

        return response()->json($this->payload->summary($conversation));
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        abort_unless(ChatAbilities::allows('delete', $request->user()), 403);

        $conversation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Thumbs up / down on one answer.
     *
     * Writes rag_queries.feedback, which has existed since the first migration
     * and until now had nothing writing to it -- this is the evaluation signal
     * the query log was designed to collect.
     */
    public function feedback(Request $request, QueryLog $query): JsonResponse
    {
        abort_unless(ChatAbilities::allows('feedback', $request->user()), 403);

        $data = $request->validate([
            'feedback' => ['required', Rule::in([-1, 0, 1])],
        ]);

        $owner = $request->user()?->getAuthIdentifier();
        $owner = $owner === null ? null : (string) $owner;

        if (! ChatAbilities::allows('all_conversations', $request->user())) {
            abort_unless($query->user_id === $owner, 404);
        }

        $query->forceFill(['feedback' => (int) $data['feedback']])->save();

        return response()->json(['feedback' => $query->feedback]);
    }

    private function page(Request $request, ?Conversation $conversation): View
    {
        $data = $this->payload->build($request->user(), $conversation);

        return view('rag::chat.index', [
            'payload' => $data,
            'abilities' => $data['abilities'],
            'layout' => (string) config('rag.chat.layout', 'rag::chat.layout'),
        ]);
    }
}
