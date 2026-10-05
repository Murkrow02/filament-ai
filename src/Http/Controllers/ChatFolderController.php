<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Agent\Chat\AssistantAccess;
use Murkrow\FilamentAi\Agent\Chat\ConversationFolders;
use Murkrow\FilamentAi\Chat\ChatAbilities;

/**
 * The folders of the chat's sidebar: create, rename, delete, and file a chat
 * in one.
 *
 * A folder or a chat that is not the user's answers 404, like one that never
 * existed (see ChatController::owned()).
 */
class ChatFolderController
{
    public function __construct(
        private readonly ConversationFolders $folders,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $folder = $this->folders->create($user, $data['name']);

        abort_if($folder === null, 422, 'Too many folders.');

        return response()->json(['id' => (int) $folder->id, 'name' => $folder->name], 201);
    }

    public function update(Request $request, int $folder): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $renamed = $this->folders->rename($user, $folder, $data['name']);

        abort_if($renamed === null, 404);

        return response()->json(['id' => (int) $renamed->id, 'name' => $renamed->name]);
    }

    public function destroy(Request $request, int $folder): JsonResponse
    {
        abort_unless($this->folders->delete($this->user($request), $folder), 404);

        return response()->json(['deleted' => true]);
    }

    /**
     * Put a chat in a folder, or take it out of any with `folder: null`.
     */
    public function file(Request $request, string $conversation): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['folder' => ['present', 'nullable', 'integer']]);
        $folder = $data['folder'] === null ? null : (int) $data['folder'];

        abort_unless($this->folders->move($user, $conversation, $folder), 404);

        return response()->json(['uuid' => $conversation, 'folder_id' => $folder]);
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();

        abort_unless(AssistantAccess::allows() && $this->folders->available(), 404);
        abort_if($user === null, 403);
        abort_unless(ChatAbilities::allows('history', $user) && ChatAbilities::allows('folders', $user), 403);

        return $user;
    }
}
