<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Murkrow\FilamentAi\Tests\FilamentTestCase;
use Murkrow\FilamentAi\Tests\PostgresTestCase;
use Murkrow\FilamentAi\Tests\TestCase;

uses(TestCase::class)->in('Feature');

// The pgvector suite talks to a real PostgreSQL and skips itself when none is
// reachable, so it lives in its own directory with its own base case.
uses(PostgresTestCase::class)->in('Pgvector');

// The panel suite boots a real Filament panel, which is heavier than the rest
// and only relevant when Filament is installed.
uses(FilamentTestCase::class)->in('Filament');

/*
|--------------------------------------------------------------------------
| Chat helpers
|--------------------------------------------------------------------------
|
| Shared by every test that drives the assistant over HTTP, so no test file
| has to be loaded before another for its helpers to exist.
|
*/

/**
 * @param  list<mixed>  $responses
 */
function scriptAgent(array $responses): void
{
    Ai::textProvider()->useTextGateway(new FakeTextGateway($responses));
}

/**
 * Every server-sent event of a response, in order.
 *
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function eventsOf(TestResponse $response): array
{
    preg_match_all('/event: (\S+)\ndata: (.*)\n/', $response->streamedContent(), $matches, PREG_SET_ORDER);

    return array_map(static fn (array $match): array => [
        'event' => $match[1],
        'data' => json_decode($match[2], true),
    ], $matches);
}

/**
 * @param  list<array{event: string, data: array<string, mixed>}>  $events
 * @return array<string, mixed>
 */
function lastEvent(array $events, string $name): array
{
    $found = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === $name));

    expect($found)->not->toBeEmpty("no {$name} event was sent");

    return end($found)['data'];
}

/**
 * The bootstrap payload, read back out of the rendered page.
 *
 * Asserting on the rendered HTML rather than on the component's state is
 * deliberate: every server-side assertion passed once while the approval
 * buttons were dead in the browser, and that is not a mistake worth making
 * twice.
 *
 * @return array<string, mixed>
 */
function payloadFrom(string $html): array
{
    expect($html)->toContain('id="fai-chat-payload"');

    preg_match('/<script type="application\/json" id="fai-chat-payload">(.*?)<\/script>/s', $html, $matches);

    return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
}
