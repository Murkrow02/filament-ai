<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * One try at the goal: what the agent answered, what the verifier made of it,
 * and what it cost.
 *
 * The conversation id is kept so a rejected attempt can be read back in full
 * -- the answer alone rarely explains where the agent went wrong.
 */
class SolveAttempt extends Model
{
    use UsesAiConnection;

    protected $guarded = [];

    protected function aiTableKey(): string
    {
        return 'solve_attempts';
    }

    protected function casts(): array
    {
        return [
            'status' => SolveAttemptStatus::class,
            'wave' => 'integer',
            'position' => 'integer',
            'score' => 'integer',
            'temperature' => 'float',
            'tokens_used' => 'integer',
            'cost_micros' => 'integer',
            'duration_ms' => 'integer',
            'tool_calls' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SolveRun::class, 'run_id');
    }

    /**
     * The answer the attempt committed to.
     *
     * Attempts are asked to finish with an "ANSWER:" line, so a run can show
     * one line next to a page of reasoning. Without the marker the whole reply
     * is the answer, which is worse to read but never wrong.
     */
    public function finalAnswer(): string
    {
        $text = trim((string) $this->answer);

        if ($text === '' || ! str_contains($text, 'ANSWER:')) {
            return $text;
        }

        return trim((string) Str::of($text)->afterLast('ANSWER:')->before("\n"));
    }

    public function isJudged(): bool
    {
        return in_array($this->status, [SolveAttemptStatus::Accepted, SolveAttemptStatus::Rejected], true);
    }
}
