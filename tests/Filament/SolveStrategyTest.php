<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Murkrow\FilamentAi\Agent\Solving\DefaultStrategy;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Agent\Solving\Strategies;
use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Data\SolvePhase;
use Murkrow\FilamentAi\Data\Verdict;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;
use Murkrow\FilamentAi\Tests\Fixtures\KnownAnswerStrategy;
use Murkrow\FilamentAi\Tests\Fixtures\StagedStrategy;

/*
 * A run's method belongs to the application. The engine only asks the strategy
 * what this wave should try, with which tools, and what counts as an answer --
 * everything else (waves, budgets, bookkeeping) stays the package's.
 */

beforeEach(function (): void {
    config()->set('filament-ai.agent.solving.enabled', true);
    config()->set('filament-ai.agent.solving.queue.connection', 'database');
    config()->set('filament-ai.agent.solving.queue.queue', 'rag');
    config()->set('queue.default', 'database');

    $this->createQueueTables();
});

/**
 * @param  callable(string): (string|array<string, mixed>)  $script
 */
function scriptStrategy(callable $script): void
{
    Ai::textProvider()->useTextGateway(new FakeTextGateway(
        fn (string $prompt): string|array => $script($prompt),
    ));
}

it('walks through the phases, one per wave, with their own attempts', function (): void {
    $prompts = [];

    scriptStrategy(function (string $prompt) use (&$prompts): string|array {
        if (str_contains($prompt, 'ANSWER TO GRADE')) {
            return ['accepted' => false, 'score' => 30, 'reason' => 'Non e una parola sola.'];
        }

        $prompts[] = $prompt;

        return 'ANSWER: qualcosa';
    });

    $run = app(Solver::class)->solve('Trova la parola.', new SolveOptions(
        criteria: 'Deve stare nell indizio.',
        strategy: StagedStrategy::class,
    ));

    $this->drainQueue();
    $run->refresh();

    $first = $run->attempts()->where('wave', 1)->get();
    $second = $run->attempts()->where('wave', 2)->get();

    expect($run->strategy)->toBe(StagedStrategy::class)
        // The method has two steps, so the run has two waves -- not the three
        // the config would have given it.
        ->and($run->waves_total)->toBe(2)
        ->and($first)->toHaveCount(2)
        ->and($second)->toHaveCount(1)
        ->and($first->pluck('phase')->unique()->all())->toBe(['anagrams'])
        ->and($second->pluck('phase')->unique()->all())->toBe(['archive'])
        // A phase that pins the temperature pins it for every attempt of that
        // wave; the next phase goes back to the spread.
        ->and($first->pluck('temperature')->unique()->all())->toBe([0.9])
        ->and($prompts[0])->toContain('STEP: anagrams')
        ->and($prompts[0])->toContain('permutation')
        ->and(end($prompts))->toContain('STEP: archive')
        ->and(end($prompts))->toContain('REJECTED BEFORE:');
});

it('judges against the criteria the strategy sharpened', function (): void {
    // The verifier bound in the container is what a run uses when its strategy
    // has none of its own; this one only records what it was asked to check.
    $recorder = new class implements Verifier
    {
        /** @var array<int, string> */
        public array $seen = [];

        public function verify(SolveAttempt $attempt, string $goal, string $criteria): Verdict
        {
            $this->seen[] = $criteria;

            return Verdict::accept(100, 'ok');
        }
    };

    app()->instance(Verifier::class, $recorder);

    scriptStrategy(fn (string $prompt): string => 'ANSWER: Roma');

    $run = app(Solver::class)->solve('Trova la parola.', new SolveOptions(
        criteria: 'Deve stare nell indizio.',
        strategy: StagedStrategy::class,
    ));

    $this->drainQueue();
    $run->refresh();

    expect($run->status)->toBe(SolveStatus::Solved)
        ->and($recorder->seen)->not->toBeEmpty()
        ->and($recorder->seen[0])->toBe('Deve stare nell indizio. It must be a single word.')
        // The run still stores what the caller asked for: the sharpening
        // belongs to the method, and a method can be swapped.
        ->and($run->criteria)->toBe('Deve stare nell indizio.');
});

it('never calls the judge when the strategy can decide for itself', function (): void {
    $calls = 0;

    scriptStrategy(function (string $prompt) use (&$calls): string|array {
        $calls++;

        if (str_contains($prompt, 'ANSWER TO GRADE')) {
            throw new RuntimeException('the judge must not be asked');
        }

        return 'ANSWER: Roma';
    });

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(
        attemptsPerWave: 1,
        maxWaves: 2,
        strategy: KnownAnswerStrategy::class,
    ));

    $this->drainQueue();
    $run->refresh();

    expect($run->status)->toBe(SolveStatus::Solved)
        ->and($run->message)->toBe('Roma')
        // One call: the attempt. A deterministic verifier costs nothing.
        ->and($calls)->toBe(1)
        ->and($run->attempts()->first()->reason)->toBe('Matches the known answer.');
});

it('keeps producing the prompt every run had before strategies existed', function (): void {
    $run = new SolveRun([
        'goal' => 'Trova la citta.',
        'criteria' => 'Una citta italiana.',
        'context' => ['hunt' => 12],
    ]);

    $prompt = (new DefaultStrategy)->promptFor($run, new SolvePhase, 1, 'Milano no.');

    expect($prompt)->toContain('GOAL:')
        ->toContain('Trova la citta.')
        ->toContain('CONTEXT:')
        ->toContain('"hunt": 12')
        ->toContain('WHAT COUNTS AS A SOLUTION:')
        ->toContain('EARLIER ATTEMPTS WERE REJECTED:')
        ->toContain('Milano no.')
        ->toContain('ANSWER: <your answer>')
        // Nothing to instruct: the default method has no steps of its own.
        ->not->toContain('HOW TO GO ABOUT IT:');
});

it('plans the attempts phase by phase', function (): void {
    // Two, then one: three attempts, whatever attempts_per_wave says.
    expect(Strategies::plannedAttempts(new StagedStrategy, 2, 4))->toBe(3)
        ->and((new SolveOptions(attemptsPerWave: 4, strategy: StagedStrategy::class))->maxAgentCalls())->toBe(3)
        ->and((new SolveOptions(attemptsPerWave: 4, maxWaves: 3))->maxAgentCalls())->toBe(12);
});

it('falls back to the default method when the stored one is gone', function (): void {
    expect(Strategies::for('App\\Solving\\Deleted'))->toBeInstanceOf(DefaultStrategy::class)
        ->and(Strategies::for(null))->toBeInstanceOf(DefaultStrategy::class)
        ->and(Strategies::for(StagedStrategy::class))->toBeInstanceOf(StagedStrategy::class);
});
