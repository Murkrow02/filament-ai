<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Murkrow\FilamentAi\Support\Arr;

/**
 * The frozen chunking parameters for one ingestion run.
 *
 * They are snapshotted into rag_ingestion_runs.chunking_params and hashed into
 * every document's params_checksum, so that changing a parameter correctly
 * marks the affected documents stale instead of silently producing a corpus
 * chunked two different ways.
 */
final readonly class ChunkingOptions
{
    /**
     * @param  array<int, class-string>  $normalizers
     */
    public function __construct(
        public int $targetTokens = 512,
        public int $maxTokens = 900,
        public int $minTokens = 48,
        public int $overlapTokens = 80,
        public int $hardSplitChars = 480,
        public bool $bridgeSegments = true,
        public string $sentenceRegex = '/(?<=[.!?])\s+/u',
        public float $charsPerToken = 3.7,
        public array $normalizers = [],
        public bool $embedContextHeader = true,
        public string $contextHeader = ':document_title - :position_label',
        public string $tokenEstimator = '',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            targetTokens: (int) ($config['target_tokens'] ?? 512),
            maxTokens: (int) ($config['max_tokens'] ?? 900),
            minTokens: (int) ($config['min_tokens'] ?? 48),
            overlapTokens: (int) ($config['overlap_tokens'] ?? 80),
            hardSplitChars: (int) ($config['hard_split_chars'] ?? 480),
            bridgeSegments: (bool) ($config['bridge_segments'] ?? true),
            sentenceRegex: (string) ($config['sentence_regex'] ?? '/(?<=[.!?])\s+/u'),
            charsPerToken: (float) ($config['chars_per_token'] ?? 3.7),
            normalizers: array_values((array) ($config['normalizers'] ?? [])),
            embedContextHeader: (bool) ($config['embed_context_header'] ?? true),
            contextHeader: (string) ($config['context_header'] ?? ':document_title - :position_label'),
            tokenEstimator: (string) ($config['token_estimator'] ?? ''),
        );
    }

    /**
     * Build from global config with an optional per-source override layer.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromConfig(array $overrides = []): self
    {
        /** @var array<string, mixed> $global */
        $global = (array) config('rag.chunking', []);

        return self::fromArray(Arr::mergeConfig($global, $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
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
        ];
    }

    /**
     * Stable fingerprint of everything that can change a chunk's boundaries or
     * its embedding input. Combined with the embedding model, this is what
     * makes incremental ingestion trustworthy.
     */
    public function checksum(string $embeddingModel, int $dimensions): string
    {
        return hash('sha256', (string) json_encode([
            'chunking' => $this->toArray(),
            'model' => $embeddingModel,
            'dimensions' => $dimensions,
        ]));
    }
}
