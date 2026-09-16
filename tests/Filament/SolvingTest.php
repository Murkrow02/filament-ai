<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\FakeTextGateway;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Exceptions\SolvingDisabledException;
use Murkrow\FilamentAi\Jobs\EvaluateWaveJob;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;

/*
 * Iterative solving end to end. The queue is the database driver and the waves
 * are drained by a real worker: with the sync driver a batch's `finally` never
 * fires the way it does in production, so the evaluation would never run and
 * every one of these tests would pass while proving nothing.
 *
 * Both the solver and the judge speak to the same scripted gateway, so the
 * script answers by looking at the prompt rather than by counting calls.
 */

beforeEach(function (): void {
    config()->set('rag.agent.solving.enabled', true);
    config()->set('rag.agent.solving.queue.connection', 'database');
    config()->set('rag.agent.solving.queue.queue', 'rag');
    config()->set('queue.default', 'database');

    $this->createQueueTables();
});

/**
 * @param  callable(string): (string|array<string, mixed>)  $script
 */
function scriptSolving(callable $script): void
{
    Ai::textProvider()->useTextGateway(new FakeTextGateway(
        fn (string $prompt): string|array => $script($prompt),
    ));
}

function judging(string $prompt): bool
{
    return str_contains($prompt, 'ANSWER TO GRADE');
}

it('refuses to start when solving is switched off', function (): void {
    config()->set('rag.agent.solving.enabled', false);

    app(Solver::class)->solve('Trova la parola chiave.');
})->throws(SolvingDisabledException::class);

it('runs a wave of independent attempts in parallel', function (): void {
    scriptSolving(fn (string $prompt): string|array => judging($prompt)
        ? ['accepted' => false, 'score' => 10, 'reason' => 'Non e una citta.']
        : 'ANSWER: qualcosa');

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(
        criteria: 'Il nome di una citta italiana.',
        attemptsPerWave: 3,
        maxWaves: 1,
    ));

    $this->drainQueue();

    $attempts = SolveAttempt::query()->where('run_id', $run->id)->where('wave', 1)->get();

    expect($attempts)->toHaveCount(3)
        ->and($attempts->pluck('status')->unique()->all())->toBe([SolveAttemptStatus::Rejected])
        // Spread apart on purpose: identical temperatures would make one wave
        // three copies of the same answer.
        ->and($attempts->pluck('temperature')->unique())->toHaveCount(3);
});

it('stops as soon as an attempt is accepted', function (): void {
    scriptSolving(fn (string $prompt): string|array => judging($prompt)
        ? ['accepted' => true, 'score' => 95, 'reason' => 'Corrisponde ai criteri.']
        : 'Ho ragionato a lungo.
ANSWER: Roma');

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(criteria: 'Una citta italiana.', attemptsPerWave: 2, maxWaves: 3));

    $this->drainQueue();
    $run->refresh();

    expect($run->status)->toBe(SolveStatus::Solved)
        ->and($run->message)->toBe('Roma')
        ->and($run->best_score)->toBe(95)
        ->and($run->wave)->toBe(1)
        ->and($run->finished_at)->not->toBeNull();
});

it('starts the next wave from what the last one got wrong', function (): void {
    $prompts = [];

    scriptSolving(function (string $prompt) use (&$prompts): string|array {
        if (judging($prompt)) {
            return str_contains($prompt, 'Roma')
                ? ['accepted' => true, 'score' => 90, 'reason' => 'Giusto.']
                : ['accepted' => false, 'score' => 20, 'reason' => 'Milano non e nell indizio.'];
        }

        $prompts[] = $prompt;

        return count($prompts) > 1 ? 'ANSWER: Roma' : 'ANSWER: Milano';
    });

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(criteria: 'Una citta italiana.', attemptsPerWave: 1, maxWaves: 3));

    $this->drainQueue();
    $run->refresh();

    expect($run->status)->toBe(SolveStatus::Solved)
        ->and($run->wave)->toBe(2)
        ->and($prompts[1])->toContain('EARLIER ATTEMPTS WERE REJECTED')
        ->and($prompts[1])->toContain('Milano non e nell indizio.');
});

it('gives up with a message and keeps the best attempt', function (): void {
    scriptSolving(fn (string $prompt): string|array => judging($prompt)
        ? ['accepted' => false, 'score' => 42, 'reason' => 'Manca la giustificazione.']
        : 'ANSWER: Torino');

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(criteria: 'Una citta italiana.', attemptsPerWave: 2, maxWaves: 2));

    $this->drainQueue();
    $run->refresh();

    expect($run->status)->toBe(SolveStatus::Exhausted)
        ->and($run->best_attempt_id)->not->toBeNull()
        ->and($run->best_score)->toBe(42)
        ->and($run->message)->toContain('Torino')
        ->toContain('Manca la giustificazione.')
        ->and($run->attempts()->count())->toBe(4);
});

it('treats a judge that breaks as unjudged, not as a rejection', function (): void {
    scriptSolving(function (string $prompt): string|array {
        if (judging($prompt)) {
            throw new RuntimeException('il giudice e caduto');
        }

        return 'ANSWER: Genova';
    });

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(attemptsPerWave: 1, maxWaves: 1));

    $this->drainQueue();
    $run->refresh();

    expect($run->attempts()->first()->status)->toBe(SolveAttemptStatus::Unjudged)
        ->and($run->status)->toBe(SolveStatus::Exhausted)
        // The answer survives the judge: it is still the best thing the run
        // produced, and the user gets to see it.
        ->and($run->message)->toContain('Genova');
});

it('stops when the token budget runs out, with waves still left', function (): void {
    $run = SolveRun::query()->create([
        'uuid' => (string) Str::uuid7(),
        'status' => SolveStatus::Running,
        'goal' => 'Trova la citta.',
        'criteria' => 'Una citta italiana.',
        'waves_total' => 5,
        'attempts_per_wave' => 1,
        'wave' => 1,
        'tokens_used' => 9_000,
        'budgets' => ['max_tokens' => 1_000, 'max_cost_micros' => null, 'max_seconds' => null],
        'started_at' => now(),
    ]);

    SolveAttempt::query()->create([
        'run_id' => $run->id,
        'wave' => 1,
        'position' => 1,
        'status' => SolveAttemptStatus::Answered,
        'answer' => 'ANSWER: Bologna',
    ]);

    scriptSolving(fn (string $prompt): string|array => ['accepted' => false, 'score' => 30, 'reason' => 'Non basta.']);

    app()->call([new EvaluateWaveJob($run->id, 1), 'handle']);

    $run->refresh();

    expect($run->status)->toBe(SolveStatus::Exhausted)
        ->and($run->message)->toContain('9000 token')
        ->and($run->attempts()->where('wave', 2)->count())->toBe(0);
});
