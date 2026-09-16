<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Solving;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Grades one attempt against the caller's criteria.
 *
 * Deliberately a different agent from the one doing the work, with no tools
 * and no memory: it sees the goal, the criteria and the answer, and nothing
 * else. Temperature is pinned at zero -- a grader that changes its mind
 * between identical answers makes the whole run unrepeatable.
 *
 * It runs on its own provider and model when configured, because the judging
 * is a small classification job and does not need the model that solved it.
 */
final class Judge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly string $goal,
        private readonly string $criteria,
    ) {}

    public function instructions(): string
    {
        $criteria = trim($this->criteria) === ''
            ? 'The answer must actually answer the goal, be specific, and be supported by the reasoning shown.'
            : trim($this->criteria);

        return implode("\n", [
            'You grade one answer to one goal. You are strict: an answer that is plausible but unsupported is not accepted.',
            '',
            'GOAL:',
            $this->goal,
            '',
            'ACCEPTANCE CRITERIA:',
            $criteria,
            '',
            'Accept only when the answer satisfies every criterion. Score how close it is on a scale of 0 to 100, where 100 means it satisfies all of them and 0 means it is unrelated.',
            'The reason must say what is missing or wrong in one or two sentences, because it is handed back to the solver as its next instruction. Write it in the language of the goal.',
            'Judge the answer, never the effort behind it.',
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'accepted' => $schema->boolean()
                ->description('True only when the answer satisfies every acceptance criterion.')
                ->required(),
            'score' => $schema->integer()
                ->min(0)
                ->max(100)
                ->description('How close the answer is, 0 to 100.')
                ->required(),
            'reason' => $schema->string()
                ->description('What is missing or wrong, in one or two sentences, in the language of the goal.')
                ->required(),
        ];
    }

    public function provider(): ?string
    {
        $provider = config('rag.agent.solving.judge.provider')
            ?? config('rag.agent.provider')
            ?? config('rag.llm.provider');

        return blank($provider) ? null : (string) $provider;
    }

    public function model(): ?string
    {
        $model = config('rag.agent.solving.judge.model')
            ?? config('rag.agent.model')
            ?? config('rag.llm.model');

        return blank($model) ? null : (string) $model;
    }

    public function temperature(): ?float
    {
        return 0.0;
    }
}
