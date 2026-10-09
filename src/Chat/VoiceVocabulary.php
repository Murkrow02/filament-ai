<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chat;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The words dictation should get right, as one prompt for Whisper.
 *
 * `chat.voice.prompt` (a sentence) followed by `chat.voice.phrases` (a list,
 * or a [Class::class, 'method'] callable given the person dictating -- the
 * names of their own customers, say, which no static list can know). Both
 * engines read the same text: the browser passes it as Whisper's decoder
 * prompt, the server as the transcription API's `prompt`.
 */
final class VoiceVocabulary
{
    public static function for(?Authenticatable $user): string
    {
        $phrases = [];

        foreach (self::phrases($user) as $entry) {
            $phrase = trim((string) (is_array($entry) ? ($entry['phrase'] ?? '') : $entry));

            if ($phrase !== '') {
                // The first spelling of a repeated phrase wins.
                $phrases[mb_strtolower($phrase)] ??= $phrase;
            }
        }

        $prompt = trim((string) config('filament-ai.chat.voice.prompt', ''));
        $list = $phrases === [] ? '' : implode(', ', $phrases).'.';

        return trim($prompt.' '.$list);
    }

    /**
     * @return array<int, mixed>
     */
    private static function phrases(?Authenticatable $user): array
    {
        $phrases = config('filament-ai.chat.voice.phrases', []);

        if (is_array($phrases) && array_is_list($phrases) && count($phrases) === 2
            && is_string($phrases[0]) && is_string($phrases[1])
            && class_exists($phrases[0]) && method_exists($phrases[0], $phrases[1])) {
            $phrases = app($phrases[0])->{$phrases[1]}($user);
        }

        if (! is_iterable($phrases)) {
            return [];
        }

        return array_values(is_array($phrases) ? $phrases : iterator_to_array($phrases, false));
    }
}
