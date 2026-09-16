<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * Token and cost accounting. Cost is kept in micro-USD integers so it can be
 * summed in SQL without float drift.
 */
final readonly class Usage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $embeddingTokens = 0,
        public int $costMicros = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
            $this->embeddingTokens + $other->embeddingTokens,
            $this->costMicros + $other->costMicros,
        );
    }

    public function costUsd(): float
    {
        return $this->costMicros / 1_000_000;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'embedding_tokens' => $this->embeddingTokens,
            'cost_micros' => $this->costMicros,
        ];
    }
}
