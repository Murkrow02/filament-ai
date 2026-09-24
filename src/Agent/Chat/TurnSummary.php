<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

/**
 * How a streamed turn ended: its text, its conversation and what it cost.
 */
final readonly class TurnSummary
{
    public function __construct(
        public string $text,
        public ?string $conversationId,
        public ?string $model,
        public int $inputTokens,
        public int $outputTokens,
        public float $costUsd,
    ) {}
}
