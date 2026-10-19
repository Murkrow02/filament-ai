<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Murkrow\FilamentAi\Http\ChatTurnResponder;
use Murkrow\FilamentAi\Http\Requests\AskRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asking the assistant: one turn, streamed.
 *
 * Text as it is written, a `tool` event whenever it reaches for something, an
 * `approval` event when a change needs the user's word before it runs. The
 * turn itself lives in `ChatTurnResponder`, shared with the token API.
 */
class AssistantController
{
    public function __construct(
        private readonly ChatTurnResponder $responder,
    ) {}

    /**
     * Ask the assistant.
     */
    public function ask(AskRequest $request): StreamedResponse|JsonResponse
    {
        return $this->responder->ask($request);
    }

    /**
     * Approve or reject the calls a paused turn is waiting on, and stream what
     * the agent does next.
     */
    public function decide(Request $request, string $conversation): StreamedResponse|JsonResponse
    {
        return $this->responder->decide($request, $conversation);
    }
}
