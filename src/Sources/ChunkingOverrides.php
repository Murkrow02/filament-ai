<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

/**
 * Per-source chunking parameters, layered over the global `filament-ai.chunking` block.
 *
 * Every field is nullable and only the ones set are emitted, so a source that
 * needs a smaller window says exactly that and inherits the rest. Changing any
 * of them changes the run's `params_checksum` and therefore marks the affected
 * documents stale -- that is the point, not a side effect.
 */
final readonly class ChunkingOverrides
{
    /**
     * @param  array<int, class-string>|null  $normalizers
     * @param  class-string|null  $tokenEstimator
     */
    public function __construct(
        public ?int $targetTokens = null,
        public ?int $maxTokens = null,
        public ?int $minTokens = null,
        public ?int $overlapTokens = null,
        public ?int $hardSplitChars = null,
        public ?bool $bridgeSegments = null,
        public ?string $sentenceRegex = null,
        public ?float $charsPerToken = null,
        public ?array $normalizers = null,
        public ?bool $embedContextHeader = null,
        public ?string $contextHeader = null,
        public ?string $tokenEstimator = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * The snake_case shape `ChunkingOptions` merges, without the unset keys.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'target_tokens' => $this->targetTokens,
            'max_tokens' => $this->maxTokens,
            'min_tokens' => $this->minTokens,
            'overlap_tokens' => $this->overlapTokens,
            'hard_split_chars' => $this->hardSplitChars,
            'bridge_segments' => $this->bridgeSegments,
            'sentence_regex' => $this->sentenceRegex,
            'chars_per_token' => $this->charsPerToken,
            'normalizers' => $this->normalizers,
            'embed_context_header' => $this->embedContextHeader,
            'context_header' => $this->contextHeader,
            'token_estimator' => $this->tokenEstimator,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
