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
 *
 * With the `local` engine Whisper runs in the page and nothing reaches this
 * class.
 */
final class VoiceTranscriber
{
    public const ENGINE_SERVER = 'server';

    public const ENGINE_LOCAL = 'local';

    public static function enabled(): bool
    {
        return (bool) config('filament-ai.enabled', true)
            && (bool) config('filament-ai.chat.voice.enabled', false);
    }

    /**
     * Who turns the recording into text: the server, or a Whisper model in
     * the page. Anything unknown is the server, as before the setting existed.
     */
    public static function engine(): string
    {
        $engine = strtolower(trim((string) config('filament-ai.chat.voice.engine', self::ENGINE_SERVER)));

        return $engine === self::ENGINE_LOCAL ? self::ENGINE_LOCAL : self::ENGINE_SERVER;
    }

    /**
     * Whether recordings may be uploaded here at all: never with the local
     * engine, whose audio does not leave the device.
     */
    public static function transcribesOnServer(): bool
    {
        return self::enabled() && self::engine() === self::ENGINE_SERVER;
    }

    /**
     * Whisper wants an ISO-639-1 code: "it", not "it_IT".
     */
    public static function language(): string
    {
        $language = (string) (config('filament-ai.chat.voice.language') ?: app()->getLocale());

        return strtolower(substr(str_replace('-', '_', $language), 0, 2));
    }

    public function transcribe(UploadedFile $audio, string $vocabulary = ''): string
    {
        $pending = Transcription::of($audio)
            ->language(self::language())
            ->timeout(max(1, (int) config('filament-ai.chat.voice.timeout', 15)));

        if ($vocabulary !== '') {
            $pending->withProviderOptions(['prompt' => $vocabulary]);
        }

        $text = $pending->generate(config('filament-ai.chat.voice.providers'))->text;

        return trim(Str::squish($text));
    }
}
