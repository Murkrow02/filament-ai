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
use Murkrow\FilamentAi\Agent\Solving\SolveScope;
use Murkrow\FilamentAi\Agent\Solving\Strategies;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Jobs\Concerns\InteractsWithSolvingQueue;
use Murkrow\FilamentAi\Models\SolveAttempt;
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

        // Back into the panel, tenant and user the question was asked in; the
        // resource tools stay off when that cannot be done.
        SolveScope::restore($run);

        $strategy = Strategies::for($run->strategy);
        $phase = $strategy->phaseFor((int) $attempt->wave);

        $assistant = app($run->assistant ?: PanelAssistant::class);

        if ($assistant instanceof PanelAssistant) {
            if ($attempt->temperature !== null) {
                $assistant = $assistant->withTemperature((float) $attempt->temperature);
            }

            // A phase that names its tools gets only those: "try the anagrams
            // first" means nothing if the archive is one call away.
            // Nobody is there to approve a write, so an attempt gets none.
            $assistant = $assistant->onlyTools($phase->tools)->withMaxSteps($phase->maxSteps)->withoutWrites();
        }

        $prompt = $strategy->promptFor($run, $phase, (int) $attempt->position, $this->feedback);

        $startedAt = hrtime(true);
        $response = $assistant->prompt($prompt);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $promptTokens = (int) ($response->usage->inputTokens ?? 0);
        $completionTokens = (int) ($response->usage->outputTokens ?? 0);
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
        return ['filament-ai', 'ai:solve', 'ai:solve:attempt:'.$this->attemptId];
    }
}
