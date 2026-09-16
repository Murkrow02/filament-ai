<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Agent\Solving\SolveProgress;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Contracts\Verifier;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Events\SolveWaveCompleted;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithSolvingQueue;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;

/**
 * Judges a finished wave and decides what happens next.
 *
 * The only place where a run ends. Every budget is checked here, and every
 * one of them is a reason to stop: a search nobody can stop is not a feature,
 * it is a bill.
 */
final class EvaluateWaveJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithSolvingQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $runId,
        public readonly int $wave,
    ) {
        $this->configureSolvingQueue();
    }

    public function handle(Verifier $verifier, Solver $solver): void
    {
        $run = SolveRun::query()->find($this->runId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $attempts = $run->attempts()->where('wave', $this->wave)->orderBy('position')->get();
        $accepted = null;
        $reasons = [];

        foreach ($attempts as $attempt) {
            if ($attempt->status === SolveAttemptStatus::Pending) {
                // The batch finished without this one reporting: a worker died,
                // or the job was dropped. Count it rather than wait forever.
                $attempt->forceFill([
                    'status' => SolveAttemptStatus::Failed,
                    'error' => $attempt->error ?? 'The attempt never reported back.',
                ])->save();

                continue;
            }

            if ($attempt->status !== SolveAttemptStatus::Answered) {
                continue;
            }

            $verdict = $verifier->verify($attempt, $run->goal, (string) $run->criteria);

            $attempt->forceFill([
                'status' => match (true) {
                    ! $verdict->judged => SolveAttemptStatus::Unjudged,
                    $verdict->accepted => SolveAttemptStatus::Accepted,
                    default => SolveAttemptStatus::Rejected,
                },
                'score' => $verdict->score,
                'reason' => $verdict->reason,
            ])->save();

            SolveProgress::judged($run, $verdict->tokens, $verdict->costMicros);

            if ($verdict->accepted && $accepted === null) {
                $accepted = $attempt;

                continue;
            }

            if ($verdict->judged && trim($verdict->reason) !== '') {
                $reasons[] = '- '.Str::limit($attempt->finalAnswer(), 120).' -> '.trim($verdict->reason);
            }
        }

        $run->refresh();
        $best = $this->rememberBest($run);

        if ($accepted !== null) {
            SolveProgress::finish($run, SolveStatus::Solved, $accepted->finalAnswer());
            SolveWaveCompleted::dispatch($run->refresh(), $this->wave, true);

            return;
        }

        if (($stop = $this->reasonToStop($run)) !== null) {
            SolveProgress::finish($run, SolveStatus::Exhausted, $this->exhaustedMessage($run, $best, $stop));
            SolveWaveCompleted::dispatch($run->refresh(), $this->wave, false);

            return;
        }

        SolveWaveCompleted::dispatch($run, $this->wave, false);

        $solver->dispatchWave($run, $this->wave + 1, $this->feedbackFrom($reasons, $best));
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['rag', 'rag:solve', 'rag:solve:run:'.$this->runId];
    }

    private function rememberBest(SolveRun $run): ?SolveAttempt
    {
        $best = $run->attempts()
            ->whereIn('status', [SolveAttemptStatus::Accepted->value, SolveAttemptStatus::Rejected->value])
            ->orderByDesc('score')
            ->orderBy('id')
            ->first();

        // Nothing was judged: an unjudged answer is still better than none.
        $best ??= $run->attempts()
            ->where('status', SolveAttemptStatus::Unjudged->value)
            ->orderBy('id')
            ->first();

        if ($best !== null) {
            $run->forceFill(['best_attempt_id' => $best->id, 'best_score' => (int) $best->score])->save();
        }

        return $best;
    }

    /**
     * Which budget ran out, as a translated phrase, or null to keep going.
     */
    private function reasonToStop(SolveRun $run): ?string
    {
        $budgets = (array) $run->budgets;

        if ($this->wave >= (int) $run->waves_total) {
            return (string) __('rag::rag.solving.stopped_waves', ['waves' => $run->waves_total]);
        }

        $maxTokens = $budgets['max_tokens'] ?? null;

        if ($maxTokens !== null && $run->tokens_used >= (int) $maxTokens) {
            return (string) __('rag::rag.solving.stopped_tokens', ['tokens' => $run->tokens_used]);
        }

        $maxCost = $budgets['max_cost_micros'] ?? null;

        if ($maxCost !== null && $run->cost_micros >= (int) $maxCost) {
            return (string) __('rag::rag.solving.stopped_cost', ['cost' => number_format($run->costUsd(), 2)]);
        }

        $maxSeconds = $budgets['max_seconds'] ?? null;

        if ($maxSeconds !== null && ($run->durationSeconds() ?? 0) >= (int) $maxSeconds) {
            return (string) __('rag::rag.solving.stopped_seconds', ['seconds' => $run->durationSeconds()]);
        }

        return null;
    }

    private function exhaustedMessage(SolveRun $run, ?SolveAttempt $best, string $stop): string
    {
        if ($best === null) {
            return (string) __('rag::rag.solving.exhausted_empty', ['stop' => $stop]);
        }

        return (string) __('rag::rag.solving.exhausted', [
            'stop' => $stop,
            'attempts' => $run->attempts_total,
            'answer' => Str::limit($best->finalAnswer(), 300),
            'reason' => trim((string) $best->reason) === ''
                ? (string) __('rag::rag.solving.no_reason')
                : trim((string) $best->reason),
        ]);
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function feedbackFrom(array $reasons, ?SolveAttempt $best): string
    {
        $lines = [];

        if ($best !== null && trim((string) $best->reason) !== '') {
            $lines[] = 'Closest so far ('.$best->score.'/100): '.Str::limit($best->finalAnswer(), 200);
            $lines[] = 'Rejected because: '.trim((string) $best->reason);
            $lines[] = '';
        }

        if ($reasons !== []) {
            $lines[] = 'Also rejected:';
            // A wave's worth of reasons is enough context; more would crowd
            // out the goal itself.
            $lines = [...$lines, ...array_slice($reasons, 0, 6)];
        }

        return implode("\n", $lines);
    }
}
