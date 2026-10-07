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
        ->assertSee('"voice":{"maxSeconds":60}', escape: false);

    config()->set('filament-ai.chat.voice.enabled', false);

    $this->get('/ai/chat')->assertOk()
        ->assertSee('"voice":null', escape: false);
});
