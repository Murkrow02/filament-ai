<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * One retrieval hit: enough to render a citation without another query.
 */
final readonly class ScoredChunk
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, float>|null  $vector  only loaded when MMR needs it
     */
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public string $sourceKey,
        public string $externalId,
        public ?string $documentTitle,
        public int $ordinal,
        public int $positionStart,
        public int $positionEnd,
        public string $content,
        public string $contentHash,
        public float $score,
        public array $metadata = [],
        public ?array $vector = null,
        public ?string $url = null,
    ) {}

    public function withScore(float $score): self
    {
        return new self(
            $this->chunkId, $this->documentId, $this->sourceKey, $this->externalId,
            $this->documentTitle, $this->ordinal, $this->positionStart, $this->positionEnd,
            $this->content, $this->contentHash, $score, $this->metadata, $this->vector, $this->url,
        );
    }

    /**
     * @param  array<int, float>|null  $vector
     */
    public function withVector(?array $vector): self
    {
        return new self(
            $this->chunkId, $this->documentId, $this->sourceKey, $this->externalId,
            $this->documentTitle, $this->ordinal, $this->positionStart, $this->positionEnd,
            $this->content, $this->contentHash, $this->score, $this->metadata, $vector, $this->url,
        );
    }

    public function withUrl(?string $url): self
    {
        return new self(
            $this->chunkId, $this->documentId, $this->sourceKey, $this->externalId,
            $this->documentTitle, $this->ordinal, $this->positionStart, $this->positionEnd,
            $this->content, $this->contentHash, $this->score, $this->metadata, $this->vector, $url,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'source' => $this->sourceKey,
            'external_id' => $this->externalId,
            'title' => $this->documentTitle,
            'position_start' => $this->positionStart,
            'position_end' => $this->positionEnd,
            'score' => round($this->score, 4),
            'content' => $this->content,
            'url' => $this->url,
        ];
    }
}
