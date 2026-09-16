<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking;

use Murkrow\FilamentAi\Contracts\TokenEstimator;

/**
 * Character-ratio token estimate.
 *
 * Deliberately dependency-free: exact BPE counting needs a vocabulary file and
 * costs meaningfully more CPU on a corpus of millions of chunks, while the only
 * decisions that depend on the count -- window size and overlap -- tolerate a
 * few percent of error. Install yethee/tiktoken and swap in TiktokenEstimator
 * when exactness matters (for example when charging back per token).
 */
final class HeuristicTokenEstimator implements TokenEstimator
{
    public function __construct(
        private readonly float $charsPerToken = 3.7,
    ) {}

    public function withCharsPerToken(float $charsPerToken): self
    {
        return new self($charsPerToken);
    }

    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) max(1, (int) ceil(mb_strlen($text) / $this->charsPerToken));
    }
}
