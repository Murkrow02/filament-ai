<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Data\SolveOptions;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Events\SolveRunStarted;
use Murkrow\FilamentAi\Exceptions\SolvingDisabledException;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithSolvingQueue;
use Murkrow\FilamentAi\Jobs\EvaluateWaveJob;
use Murkrow\FilamentAi\Jobs\SolveAttemptJob;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * Starts an iterative search and launches each wave of it.
 *
 * It holds no opinion about answers: the attempts are jobs, the judging is
 * `EvaluateWaveJob`'s, and the decision to keep going comes back here only as
 * a call to `dispatchWave()`.
 */
final class Solver
{
    use InteractsWithSolvingQueue;

    public function solve(string $goal, SolveOptions $options = new SolveOptions, int|string|null $createdBy = null): SolveRun
    {
        if (! config('rag.agent.solving.enabled', false)) {
            throw SolvingDisabledException::make();
        }

        $goal = trim($goal);

        $run = SolveRun::query()->create([
            'uuid' => (string) Str::uuid7(),
            'status' => SolveStatus::Queued,
            'goal' => $goal,
            'criteria' => $options->criteria,
            'context' => $options->context,
            'assistant' => $options->assistant(),
            'waves_total' => $options->maxWaves(),
            'attempts_per_wave' => $options->attemptsPerWave(),
            // Snapshot of what it was allowed to spend: the config may have
            // changed by the time somebody asks why the run stopped.
            'budgets' => [
                'max_tokens' => $options->maxTokens(),
                'max_cost_micros' => $options->maxCostMicros(),
                'max_seconds' => $options->maxSeconds(),
                'temperature_spread' => $options->temperatureSpread(),
            ],
            'created_by' => $createdBy === null ? null : (string) $createdBy,
        ]);

        SolveRunStarted::dispatch($run);

        $this->dispatchWave($run, 1);

        return $run->refresh();
    }

    /**
     * One wave: N independent attempts, then a single evaluation of all of
     * them. The attempts never see each other -- that is where the variety
     * comes from -- while `$feedback` carries what the previous wave got wrong.
     */
    public function dispatchWave(SolveRun $run, int $wave, string $feedback = ''): void
    {
        $count = max(1, (int) $run->attempts_per_wave);
        $spread = (float) ($run->budgets['temperature_spread'] ?? 0.4);
        $jobs = [];

        for ($position = 1; $position <= $count; $position++) {
            $attempt = SolveAttempt::query()->create([
                'run_id' => $run->id,
                'wave' => $wave,
                'position' => $position,
                'status' => SolveAttemptStatus::Pending,
                'temperature' => $this->temperatureFor($position, $count, $spread),
            ]);

            $jobs[] = new SolveAttemptJob($attempt->id, $feedback);
        }

        $runId = $run->id;

        $batch = Bus::batch($jobs)
            ->name("rag:solve:{$run->uuid}:wave-{$wave}")
            ->onConnection(self::solvingConnection())
            ->onQueue(self::solvingQueue())
            // One attempt blowing up must not cancel its siblings: a wave of
            // four where one fails is still three answers to judge.
            ->allowFailures(true)
            ->finally(static fn () => EvaluateWaveJob::dispatch($runId, $wave))
            ->dispatch();

        $run->forceFill([
            'status' => SolveStatus::Running,
            'wave' => $wave,
            'batch_id' => $batch->id,
            'started_at' => $run->started_at ?? now(),
        ])->save();
    }

    /**
     * Attempts in a wave are spread from the configured temperature upwards,
     * so the first stays close to how the assistant normally answers and the
     * last is the one allowed to be odd.
     */
    private function temperatureFor(int $position, int $count, float $spread): float
    {
        $base = config('rag.llm.temperature');
        $base = $base === null || $base === '' ? 0.2 : (float) $base;

        if ($count < 2 || $spread <= 0.0) {
            return $base;
        }

        return min(1.0, round($base + $spread * (($position - 1) / ($count - 1)), 2));
    }
}
