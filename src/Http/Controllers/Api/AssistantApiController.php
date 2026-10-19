<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Http\ChatTurnResponder;
use Murkrow\FilamentAi\Http\Requests\AskRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asking and deciding, for a client that authenticates with a token.
 *
 * The same turn as the browser chat (see `ChatTurnResponder`), answered by
 * `filament-ai.api.assistant` and, unless `filament-ai.api.resources` boots a
 * panel, without the panel's resource tools. `?stream=0` answers with the
 * final `done` document instead of a stream.
 */
class AssistantApiController
{
    public function __construct(
        private readonly ChatTurnResponder $responder,
    ) {}

    public function ask(AskRequest $request): StreamedResponse|JsonResponse
    {
        return $this->responder->ask($request, $this->assistant(), $this->resources(), $this->streams($request));
    }

    public function decide(Request $request, string $conversation): StreamedResponse|JsonResponse
    {
        return $this->responder->decide($request, $conversation, $this->assistant(), $this->resources(), $this->streams($request));
    }

    /**
     * @return class-string<\Murkrow\FilamentAi\Agent\PanelAssistant>|null
     */
    private function assistant(): ?string
    {
        $assistant = config('filament-ai.api.assistant');

        return is_string($assistant) && $assistant !== '' ? $assistant : null;
    }

    private function resources(): bool
    {
        return (bool) config('filament-ai.api.resources', false);
    }

    private function streams(Request $request): bool
    {
        return $request->boolean('stream', true);
    }
}
