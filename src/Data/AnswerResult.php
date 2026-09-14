<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Illuminate\Support\Collection;

final readonly class AnswerResult
{
    /**
     * @param  Collection<int, Citation>  $citations
     */
    public function __construct(
        public string $question,
        public string $answer,
        public Collection $citations,
        public RetrievalResult $retrieval,
        public Usage $usage,
        public bool $refused = false,
        public ?string $model = null,
        public int $latencyMs = 0,
        public ?string $queryUuid = null,
    ) {}

    /**
     * @return Collection<int, Citation>
     */
    public function usedCitations(): Collection
    {
        return $this->citations->filter(static fn (Citation $c): bool => $c->used)->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'answer' => $this->answer,
            'refused' => $this->refused,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'usage' => $this->usage->toArray(),
            'citations' => $this->citations->map(static fn (Citation $c): array => $c->toArray())->all(),
        ];
    }
}
