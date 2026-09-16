<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Agent\Solving\SolveProgress;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithSolvingQueue;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;
use Throwable;

/**
 * One attempt at the goal: a full turn of the assistant, with every tool it
 * normally has.
 *
 * Attempts of the same wave run side by side and never see each other, which
 * is the point: four answers written independently beat four rewrites of the
 * same one. What the previous *wave* got wrong arrives as `$feedback`.
 *
 * It does not retry. A second run of the same attempt would cost another
 * model call to produce a different answer anyway, and the wave has siblings.
 */
final class SolveAttemptJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithSolvingQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $attemptId,
        public readonly string $feedback = '',
    ) {
        $this->configureSolvingQueue();
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $attempt = SolveAttempt::query()->find($this->attemptId);
        $run = $attempt?->run;

        if ($attempt === null || $run === null || $run->status->isTerminal()) {
            return;
        }

        $assistant = app($run->assistant ?: PanelAssistant::class);

        if ($assistant instanceof PanelAssistant && $attempt->temperature !== null) {
            $assistant = $assistant->withTemperature((float) $attempt->temperature);
        }

        $startedAt = hrtime(true);
        $response = $assistant->prompt($this->promptFor($run));
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $promptTokens = (int) ($response->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $costMicros = CostCalculator::completionMicros(
            (string) ($response->meta->model ?? ''),
            $promptTokens,
            $completionTokens,
        );

        $attempt->forceFill([
            'status' => SolveAttemptStatus::Answered,
            'answer' => $response->text,
            'tool_calls' => $response->toolCalls->map(static fn ($call): string => $call->name)->values()->all(),
            'conversation_id' => $response->conversationId,
            'tokens_used' => $promptTokens + $completionTokens,
            'cost_micros' => $costMicros,
            'duration_ms' => $durationMs,
        ])->save();

        SolveProgress::attemptFinished($run, $promptTokens + $completionTokens, $costMicros);
    }

    public function failed(Throwable $exception): void
    {
        $attempt = SolveAttempt::query()->find($this->attemptId);

        if ($attempt === null) {
            return;
        }

        $attempt->forceFill([
            'status' => SolveAttemptStatus::Failed,
            'error' => $exception->getMessage(),
        ])->save();

        SolveProgress::attemptFinished($attempt->run_id, 0, 0, failed: true);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['rag', 'rag:solve', 'rag:solve:attempt:'.$this->attemptId];
    }

    private function promptFor(SolveRun $run): string
    {
        $lines = ['GOAL:', $run->goal];

        if (! empty($run->context)) {
            $lines[] = '';
            $lines[] = 'CONTEXT:';
            $lines[] = (string) json_encode($run->context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        if (filled($run->criteria)) {
            $lines[] = '';
            $lines[] = 'WHAT COUNTS AS A SOLUTION:';
            $lines[] = (string) $run->criteria;
        }

        if (trim($this->feedback) !== '') {
            $lines[] = '';
            $lines[] = 'EARLIER ATTEMPTS WERE REJECTED:';
            $lines[] = trim($this->feedback);
            $lines[] = 'Do not repeat them. Try a different line of reasoning.';
        }

        $lines[] = '';
        // The marker is what lets the run show a one-line answer next to a
        // page of reasoning.
        $lines[] = 'Work it out with the tools you have rather than guessing, then finish with a single line: ANSWER: <your answer>';

        return implode("\n", $lines);
    }
}
