<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Transcription;

/**
 * Turns a dictated message into text.
 *
 * The only place the chat's transport reaches laravel/ai for audio, so the
 * controller never imports it. Which providers are tried, and in what order,
 * is the host's call (`filament-ai.chat.voice.providers`): laravel/ai moves on
 * to the next one on a connection error, a rate limit or an outage, which is
 * how a self-hosted Whisper server falls back to a paid API.
 */
final class VoiceTranscriber
{
    public static function enabled(): bool
    {
        return (bool) config('filament-ai.enabled', true)
            && (bool) config('filament-ai.chat.voice.enabled', false);
    }

    public function transcribe(UploadedFile $audio): string
    {
        $pending = Transcription::of($audio)
            ->language($this->language())
            ->timeout(max(1, (int) config('filament-ai.chat.voice.timeout', 15)));

        if (filled($prompt = config('filament-ai.chat.voice.prompt'))) {
            $pending->withProviderOptions(['prompt' => (string) $prompt]);
        }

        $text = $pending->generate(config('filament-ai.chat.voice.providers'))->text;

        return trim(Str::squish($text));
    }

    /**
     * Whisper wants an ISO-639-1 code: "it", not "it_IT".
     */
    private function language(): string
    {
        $language = (string) (config('filament-ai.chat.voice.language') ?: app()->getLocale());

        return strtolower(substr(str_replace('-', '_', $language), 0, 2));
    }
}
