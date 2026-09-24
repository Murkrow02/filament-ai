<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * The whole write path through laravel/ai: the model calls a write tool, the
 * turn pauses with a pending approval and nothing is saved, and only the
 * user's decision runs the tool. Resuming reads the paused tool call back from
 * the stored conversation, so these tests need laravel/ai's tables.
 *
 * The scripted gateway is mounted on the text provider, not registered with
 * `PanelAssistant::fake()`: laravel/ai deliberately skips approval resumption
 * for a faked agent (`ResumesToolApprovals::resumesAgainstRealGateway()`), so a
 * faked agent would answer the decision without ever running the tool.
 */

/**
 * @param  list<mixed>  $responses
 */
function scriptAgentResponses(array $responses): void
{
    Ai::textProvider()->useTextGateway(new FakeTextGateway($responses));
}

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    // Otherwise the first message asks the model for a conversation title,
    // which would consume one of the scripted responses.
    config()->set('ai.conversations.generate_title', false);
});

it('pauses a write for approval and runs it only once approved', function (): void {
    scriptAgentResponses([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'I added the book.',
    ]);

    $user = auth()->user();
    $response = (new PanelAssistant)->forUser($user)->prompt('Add a book called Statuti del comune');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals->first()->tool)->toBe('test_books_create')
        ->and($response->pendingApprovals->first()->reason)->toBe('Create test book — Title: Statuti del comune')
        ->and(TestBook::query()->count())->toBe(0);

    $resumed = (new PanelAssistant)
        ->continue($response->conversationId, as: $user)
        ->prompt(Decisions::from(['call_1' => Decision::approve()]));

    expect(TestBook::query()->sole()->title)->toBe('Statuti del comune')
        ->and($resumed->text)->toBe('I added the book.');
});

it('saves nothing when the user rejects the write', function (): void {
    scriptAgentResponses([
        new ToolCall('call_1', 'test_books_create', ['title' => 'Statuti del comune']),
        'Understood, I will not add it.',
    ]);

    $user = auth()->user();
    $response = (new PanelAssistant)->forUser($user)->prompt('Add a book called Statuti del comune');

    expect($response->hasPendingApprovals())->toBeTrue();

    $resumed = (new PanelAssistant)
        ->continue($response->conversationId, as: $user)
        ->prompt(Decisions::from(['call_1' => Decision::reject('The user does not want this book.')]));

    expect(TestBook::query()->count())->toBe(0)
        ->and($resumed->text)->toBe('Understood, I will not add it.');
});
