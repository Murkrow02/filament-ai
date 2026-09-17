<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Livewire\Livewire;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Filament\Pages\AssistantChat;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;

/*
 * The "keep trying" toggle, from the page to the finished run.
 *
 * The stream follows a run that happens on the queue, and a test has no
 * worker running beside the request. So the solver is replaced with one that
 * hands back a run which already ended -- what is under test here is the
 * page's side: that the toggle exists only when it should, that the request
 * starts a run instead of a turn, and that the run's outcome reaches the page.
 * The engine itself is covered by SolvingTest and SolveStrategyTest.
 */

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
});

/**
 * A solver that records what it was asked and returns a run that is over.
 */
function finishedSolver(SolveStatus $status, string $answer): object
{
    return new class($status, $answer)
    {
        /** @var list<array{goal: string, options: SolveOptions}> */
        public array $calls = [];

        public function __construct(private SolveStatus $status, private string $answer) {}

        public function solve(string $goal, SolveOptions $options = new SolveOptions, int|string|null $createdBy = null): SolveRun
        {
            $this->calls[] = ['goal' => $goal, 'options' => $options];

            $run = SolveRun::query()->create([
                'uuid' => (string) Str::uuid7(),
                'status' => $this->status,
                'goal' => $goal,
                'wave' => 2,
                'waves_total' => 3,
                'attempts_per_wave' => 2,
                'attempts_total' => 4,
                'best_score' => 92,
                'message' => $this->status === SolveStatus::Solved ? $this->answer : 'Nessuna risposta regge.',
                'budgets' => ['max_seconds' => 5],
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $best = SolveAttempt::query()->create([
                'run_id' => $run->id,
                'wave' => 2,
                'position' => 1,
                'status' => SolveAttemptStatus::Accepted,
                'answer' => "Ragionamento.\nANSWER: {$this->answer}",
                'score' => 92,
            ]);

            $run->forceFill(['best_attempt_id' => $best->id])->save();

            return $run;
        }
    };
}

it('offers the toggle only where solving is switched on', function (): void {
    $html = Livewire::test(AssistantChat::class)->html();

    expect($html)->toContain('id="rag-solve"');

    config()->set('rag.agent.solving.enabled', false);

    expect(Livewire::test(AssistantChat::class)->html())->not->toContain('id="rag-solve"');
});

it('hides the toggle from a user who may not use it', function (): void {
    config()->set('rag.chat.abilities.solve', false);

    expect(Livewire::test(AssistantChat::class)->html())->not->toContain('id="rag-solve"');
});

it('starts a run instead of a turn and streams its outcome', function (): void {
    app()->instance(Solver::class, $solver = finishedSolver(SolveStatus::Solved, 'Roma'));

    $response = $this->post('/rag/chat/ask', [
        'question' => 'Trova la citta nascosta nell indizio',
        'mode' => 'agent',
        'solve' => true,
    ]);

    $response->assertOk();
    $body = $response->streamedContent();

    preg_match('/event: solve\ndata: (.*)\n/', $body, $solve);
    preg_match('/event: done\ndata: (.*)\n/', $body, $done);

    $progress = json_decode($solve[1], true);
    $final = json_decode($done[1], true);

    expect($solver->calls)->toHaveCount(1)
        ->and($solver->calls[0]['goal'])->toBe('Trova la citta nascosta nell indizio')
        ->and($progress['status'])->toBe('solved')
        ->and($progress['wave'])->toBe(2)
        ->and($progress['attempts_total'])->toBe(6) // default method: 3 waves x 2
        ->and($progress['best_score'])->toBe(92)
        ->and($final['answer'])->toBe('Roma')
        ->and($final['solve']['run'])->toBe(SolveRun::query()->sole()->uuid);
});

it('tells the user when nothing held up', function (): void {
    app()->instance(Solver::class, finishedSolver(SolveStatus::Exhausted, 'Roma'));

    $body = $this->post('/rag/chat/ask', [
        'question' => 'Trova la citta nascosta nell indizio',
        'mode' => 'agent',
        'solve' => true,
    ])->streamedContent();

    preg_match('/event: done\ndata: (.*)\n/', $body, $done);

    expect(json_decode($done[1], true)['answer'])->toBe('Nessuna risposta regge.');
});

it('answers once when solving is off, whatever the page sent', function (): void {
    config()->set('rag.agent.solving.enabled', false);
    app()->instance(Solver::class, $solver = finishedSolver(SolveStatus::Solved, 'Roma'));

    Ai::textProvider()->useTextGateway(new FakeTextGateway(['Una risposta sola.']));

    $body = $this->post('/rag/chat/ask', [
        'question' => 'Trova la citta nascosta nell indizio',
        'mode' => 'agent',
        'solve' => true,
    ])->streamedContent();

    expect($solver->calls)->toBe([])
        ->and($body)->not->toContain('event: solve')
        ->and($body)->toContain('Una risposta sola.');
});
