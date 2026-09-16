<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Llm\PrismLanguageModel;

/**
 * Prism's streamed events carry their text on `delta`; older releases used
 * `text`. Reading only one of the two makes every delta empty, which makes the
 * accumulated answer empty, which makes an answer that cites nothing -- and
 * the answerer then reports a perfectly good retrieval as a refusal.
 *
 * The fake language model yields plain strings, so nothing else in the suite
 * touches this mapping.
 */
function deltaOf(object $event): string
{
    $method = new ReflectionMethod(PrismLanguageModel::class, 'deltaOf');

    return $method->invoke(
        (new ReflectionClass(PrismLanguageModel::class))->newInstanceWithoutConstructor(),
        $event,
    );
}

it('reads the text of a typed Prism delta event', function (): void {
    $event = new class
    {
        public string $delta = 'Ciao mondo';

        public string $messageId = 'msg_1';
    };

    expect(deltaOf($event))->toBe('Ciao mondo');
});

it('still reads the older chunk shape', function (): void {
    $event = new class
    {
        public string $text = 'Ciao mondo';
    };

    expect(deltaOf($event))->toBe('Ciao mondo');
});

it('ignores events that carry no text at all', function (): void {
    // StreamStartEvent, StepStartEvent, TextCompleteEvent, StreamEndEvent...
    $event = new class
    {
        public string $id = 'evt_1';

        public int $timestamp = 0;
    };

    expect(deltaOf($event))->toBe('');
});

it('ignores a non-string delta rather than casting it', function (): void {
    $event = new class
    {
        public ?object $delta = null;
    };

    expect(deltaOf($event))->toBe('');
});
