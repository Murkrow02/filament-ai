<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Chat\ApprovalCards;
use Murkrow\FilamentAi\Agent\Chat\ToolLabels;
use Murkrow\FilamentAi\Tests\Fixtures\Agent\CountBooks;
use Murkrow\FilamentAi\Tests\Fixtures\Agent\RetitleBooks;
use Murkrow\FilamentAi\Tests\Fixtures\Agent\TestHostAssistant;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;

/*
 * An application's own tools: read tools on AgentTool, writes on AgentAction.
 * They must read like the derived ones in the chat (a label, a card in the
 * application's words), never throw, and never put to the user a change
 * that cannot be made.
 */

beforeEach(function (): void {
    config()->set('filament-ai.agent.assistant', TestHostAssistant::class);
    CountBooks::$available = true;

    TestBook::create(['title' => 'Statuti', 'author' => 'Ser Piero']);
    TestBook::create(['title' => 'Gabelle', 'author' => 'Ser Piero']);
});

it('names the tool after its class and answers in JSON', function (): void {
    $tool = new CountBooks;

    expect($tool->name())->toBe('count_books')
        ->and(json_decode($tool->handle(new Request(['author' => 'Ser Piero'])), true))->toBe(['author' => 'Ser Piero', 'books' => 2]);
});

it('turns a refusal into an error the model can read', function (): void {
    expect((new CountBooks)->handle(new Request(['author' => 'Nessuno'])))->toBe('Error: no book by that author.');
});

it('leaves out a tool that is not available, and refuses it if called anyway', function (): void {
    CountBooks::$available = false;

    $names = array_map(fn ($tool) => $tool->name(), iterator_to_array((new TestHostAssistant)->tools(), false));

    expect($names)->not->toContain('count_books')->toContain('retitle_books')
        ->and((new CountBooks)->handle(new Request(['author' => 'Ser Piero'])))->toStartWith('Error:');
});

it('labels host tools in the chat with their own words', function (): void {
    expect(app(ToolLabels::class)->label('count_books'))->toBe('Conto i libri')
        ->and(app(ToolLabels::class)->label('unknown_tool'))->toBe('Unknown tool');
});

it('draws the approval card the tool describes', function (): void {
    $card = app(ApprovalCards::class)->card('call_1', 'retitle_books', null, ['author' => 'Ser Piero', 'prefix' => 'I'], false);

    expect($card)->toMatchArray([
        'id' => 'call_1',
        'title' => 'Rinomina i libri',
        'record' => 'Ser Piero',
        'warnings' => ['2 libri cambiano titolo.'],
    ])->and($card['changes'][0])->toBe(['label' => 'Titolo', 'before' => 'Statuti', 'after' => 'I Statuti', 'missing' => false]);
});

it('keeps host writes when the resources are read-only', function (): void {
    config()->set('filament-ai.agent.resources.writes', false);

    $names = array_map(fn ($tool) => $tool->name(), iterator_to_array((new TestHostAssistant)->tools(), false));

    expect($names)->toContain('retitle_books')->not->toContain('test_books_create')
        ->and((new TestHostAssistant)->instructions())->toContain('tools that ask for approval (retitle_books)');
});

describe('through laravel/ai', function (): void {
    beforeEach(function (): void {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
            '--realpath' => true,
        ])->run();

        config()->set('ai.conversations.generate_title', false);
    });

    it('pauses a host write for approval and runs it once approved', function (): void {
        Ai::textProvider()->useTextGateway(new FakeTextGateway([
            new ToolCall('call_1', 'retitle_books', ['author' => 'Ser Piero', 'prefix' => 'Libro:']),
            'Fatto.',
        ]));

        $user = auth()->user();
        $response = (new TestHostAssistant)->forUser($user)->prompt('Rinomina i libri di Ser Piero');

        expect($response->hasPendingApprovals())->toBeTrue()
            ->and(TestBook::query()->where('title', 'like', 'Libro:%')->count())->toBe(0);

        (new TestHostAssistant)->continue($response->conversationId, as: $user)
            ->prompt(Decisions::from(['call_1' => Decision::approve()]));

        expect(TestBook::query()->where('title', 'like', 'Libro:%')->count())->toBe(2);
    });

    it('never asks to approve a change it would refuse', function (): void {
        Ai::textProvider()->useTextGateway(new FakeTextGateway([
            new ToolCall('call_1', 'retitle_books', ['author' => 'Nessuno', 'prefix' => 'X']),
            'Non ci sono libri di quell\'autore.',
        ]));

        $response = (new TestHostAssistant)->forUser(auth()->user())->prompt('Rinomina i libri di Nessuno');

        expect($response->hasPendingApprovals())->toBeFalse()
            ->and($response->text)->toBe('Non ci sono libri di quell\'autore.')
            ->and($response->toolResults->first()->result)->toBe('Error: no book by that author.');
    });
});
