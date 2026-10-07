<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Agent\Chat\VoiceTranscriber;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Throwable;

/**
 * A dictated message, back as text for the composer.
 *
 * Nothing is asked of the assistant here: the text goes into the input box
 * and the person sends it -- or doesn't. The recording is not kept.
 */
final class TranscriptionController
{
    /**
     * What MediaRecorder produces in the browsers that have it (Chrome and
     * Firefox: webm/ogg with opus; Safari: mp4/aac), plus the usual uploads.
     * PHP's finfo reports a webm with no video track as video/webm.
     */
    private const MIME_TYPES = [
        'audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mp4', 'video/mp4',
        'audio/x-m4a', 'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/wave',
    ];

    public function __invoke(Request $request, VoiceTranscriber $transcriber): JsonResponse
    {
        $user = $request->user();

        if (! VoiceTranscriber::enabled() || ! ChatAbilities::canUseChat($user) || ! ChatAbilities::allows('voice', $user)) {
            return response()->json(['message' => __('filament-ai::messages.chat.forbidden')], 403);
        }

        $validator = Validator::make($request->all(), [
            'audio' => [
                'required',
                'file',
                'mimetypes:'.implode(',', self::MIME_TYPES),
                'max:'.max(1, (int) config('filament-ai.chat.voice.max_kilobytes', 10240)),
            ],
        ], [], ['audio' => __('filament-ai::messages.chat.voice.attribute')]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        /** @var UploadedFile $audio */
        $audio = $request->file('audio');

        try {
            $text = $transcriber->transcribe($audio);
        } catch (Throwable $exception) {
            $reference = (string) Str::uuid();

            Log::warning('filament-ai: transcription failed', ['reference' => $reference, 'exception' => $exception]);

            return response()->json(array_filter([
                'message' => __('filament-ai::messages.chat.voice.failed'),
                'detail' => ChatAbilities::allows('debug', $user) ? $exception->getMessage().' (ref '.$reference.')' : null,
            ]), 502);
        }

        if ($text === '') {
            return response()->json(['message' => __('filament-ai::messages.chat.voice.empty')], 422);
        }

        return response()->json(['text' => $text]);
    }
}
