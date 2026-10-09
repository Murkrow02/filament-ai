<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;

/*
 * Dictation: the recording goes in, text comes back for the composer, and
 * nothing is asked of the assistant.
 */

/** A real (silent) WAV, so the server's MIME sniffing sees audio. */
function dictationWav(): UploadedFile
{
    $samples = str_repeat("\0\0", 1600);
    $header = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
        .'fmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
        .'data'.pack('V', strlen($samples));

    return UploadedFile::fake()->createWithContent('dictation.wav', $header.$samples);
}

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('filament-ai.chat.voice.enabled', true);
    config()->set('filament-ai.chat.voice.language', 'it_IT');
});

it('transcribes a recording for the composer', function (): void {
    Transcription::fake(['  In data odierna applicato   fascia A  ']);

    $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertExactJson(['text' => 'In data odierna applicato fascia A']);

    // Whisper takes ISO-639-1, not a locale.
    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'it');
});

it('refuses when dictation is switched off', function (): void {
    config()->set('filament-ai.chat.voice.enabled', false);
    Transcription::fake(['never']);

    $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json'])->assertForbidden();

    Transcription::assertNothingGenerated();
});

it('refuses a user without the voice ability', function (): void {
    config()->set('filament-ai.chat.abilities.voice', false);
    Transcription::fake(['never']);

    $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json'])->assertForbidden();
});

it('only accepts audio', function (): void {
    Transcription::fake(['never']);

    $this->post('/ai/chat/transcribe', [
        'audio' => UploadedFile::fake()->createWithContent('notes.txt', 'not a recording'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['audio']]);

    Transcription::assertNothingGenerated();
});

it('explains a failed transcription without leaking the provider error', function (): void {
    Transcription::fake(fn () => throw new RuntimeException('upstream 500: secret detail'));

    $response = $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json']);

    $response->assertStatus(502)->assertJsonMissingPath('detail');
    expect($response->json('message'))->toBe(__('filament-ai::messages.chat.voice.failed'));
});

it('reports silence instead of pasting nothing', function (): void {
    Transcription::fake(['   ']);

    $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('offers the microphone only when dictation is on', function (): void {
    $this->get('/ai/chat')->assertOk()
        ->assertSee('id="fai-mic"', escape: false)
        ->assertSee('"transcribe":"\/ai\/chat\/transcribe"', escape: false)
        ->assertSee('"voice":{"maxSeconds":60,"engine":"server","language":"it","vocabulary":"","local":null}', escape: false);

    config()->set('filament-ai.chat.voice.enabled', false);

    $this->get('/ai/chat')->assertOk()
        ->assertSee('"voice":null', escape: false);
});

it('steers the model with the configured vocabulary', function (): void {
    config()->set('filament-ai.chat.voice.prompt', 'Revenue management.');
    config()->set('filament-ai.chat.voice.phrases', ['fascia tariffaria A', 'soggiorno minimo', 'Fascia tariffaria A', ' ']);
    Transcription::fake(['ok']);

    $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json'])->assertOk();

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => ($prompt->providerOptions['prompt'] ?? null) === 'Revenue management. fascia tariffaria A, soggiorno minimo.');
});


it('runs Whisper in the page with the local engine, and never takes a recording', function (): void {
    config()->set('filament-ai.chat.voice.engine', 'local');
    config()->set('filament-ai.chat.voice.phrases', ['fascia A', 'Hotel Luca di Bacco']);
    Transcription::fake(['never']);

    $payload = $this->get('/ai/chat')->assertOk()->viewData('payload');

    expect($payload['voice'])->toBe([
        // Whisper hears one 30-second window: the local engine stops there.
        'maxSeconds' => 30,
        'engine' => 'local',
        'language' => 'it',
        'vocabulary' => 'fascia A, Hotel Luca di Bacco.',
        'local' => [
            'model' => 'onnx-community/whisper-small',
            'dtype' => 'q4',
            'library' => 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.7.5',
        ],
    ])
        // The audio never leaves the device: there is nowhere to send it.
        ->and($payload['endpoints']['transcribe'])->toBeNull();

    $this->post('/ai/chat/transcribe', ['audio' => dictationWav()], ['Accept' => 'application/json'])->assertForbidden();
    Transcription::assertNothingGenerated();
});

it('reads an unknown engine as the server', function (): void {
    config()->set('filament-ai.chat.voice.engine', 'browser');

    expect($this->get('/ai/chat')->assertOk()->viewData('payload')['voice']['engine'])->toBe('server');
});

it('asks the host for the words of the person dictating', function (): void {
    config()->set('filament-ai.chat.voice.engine', 'local');
    config()->set('filament-ai.chat.voice.prompt', 'Alberghi.');
    config()->set('filament-ai.chat.voice.phrases', [VoicePhrasesFixture::class, 'for']);

    $voice = $this->get('/ai/chat')->assertOk()->viewData('payload')['voice'];

    expect($voice['vocabulary'])->toBe('Alberghi. Hotel Luca di Bacco, user '.auth()->id().'.');
});

final class VoicePhrasesFixture
{
    /** @return list<string|array{phrase: string}> */
    public function for(mixed $user): array
    {
        return ['Hotel Luca di Bacco', ['phrase' => 'user '.$user?->getAuthIdentifier()]];
    }
}

it('passes a per-file weights map through, and keeps a shorter limit', function (): void {
    config()->set('filament-ai.chat.voice.engine', 'local');
    config()->set('filament-ai.chat.voice.max_seconds', 20);
    config()->set('filament-ai.chat.voice.local.dtype', ['encoder_model' => 'fp16', 'decoder_model_merged' => 'q4']);

    $voice = $this->get('/ai/chat')->assertOk()->viewData('payload')['voice'];

    expect($voice['maxSeconds'])->toBe(20)
        ->and($voice['local']['dtype'])->toBe(['encoder_model' => 'fp16', 'decoder_model_merged' => 'q4']);
});
