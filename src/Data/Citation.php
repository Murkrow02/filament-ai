<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

/**
 * A numbered reference handed to the model as "[#n]" and resolved back to the
 * chunk it came from when the answer is parsed.
 */
final readonly class Citation
{
    public function __construct(
        public int $marker,
        public int $rank,
        public ScoredChunk $chunk,
        public string $label,
        public bool $used = false,
    ) {}

    public function markUsed(bool $used = true): self
    {
        return new self($this->marker, $this->rank, $this->chunk, $this->label, $used);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'marker' => $this->marker,
            'rank' => $this->rank,
            'label' => $this->label,
            'used' => $this->used,
        ] + $this->chunk->toArray();
    }
}
