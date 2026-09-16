<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking;

use Generator;
use Murkrow\FilamentAi\Chunking\Normalizers\NormalizerPipeline;
use Murkrow\FilamentAi\Contracts\Chunker;
use Murkrow\FilamentAi\Data\ChunkDraft;
use Murkrow\FilamentAi\Data\ChunkingOptions;

/**
 * Sentence-aligned sliding window with overlap.
 *
 * The window advances by whole sentences and steps back far enough to carry
 * `overlap_tokens` of context into the next chunk, so a fact stated across a
 * chunk boundary still appears intact in at least one chunk. Because the
 * sentence stream carries page numbers -- and one sentence may legitimately
 * span two pages after bridging -- each chunk's page range falls out of the
 * window rather than being estimated.
 *
 * Determinism is a hard requirement: the same segments and options must always
 * produce byte-identical chunks and hashes, otherwise incremental re-ingestion
 * degenerates into a full re-embed on every run.
 */
final class SlidingWindowChunker implements Chunker
{
    public function __construct(
        private readonly TokenEstimatorFactory $estimators,
    ) {}

    /**
     * @param  iterable<int, \Murkrow\FilamentAi\Data\Segment>  $segments
     * @return Generator<int, ChunkDraft>
     */
    public function chunk(
        iterable $segments,
        ChunkingOptions $options,
        ?string $documentTitle = null,
        ?callable $positionLabel = null,
    ): Generator {
        $estimator = $this->estimators->make($options);
        $normalizer = NormalizerPipeline::fromClasses($options->normalizers);
        $splitter = new SentenceSplitter($estimator, $normalizer);

        $sentences = $splitter->split($segments, $options);

        $ordinal = 0;

        // Only ever holds the last two windows, so the trailing-stub merge can
        // reach backwards without buffering the whole document.
        $queue = [];

        foreach ($this->windows($sentences, $options) as $window) {
            $queue[] = $window;

            if (count($queue) > 2) {
                yield $this->draft(array_shift($queue), $ordinal++, $options, $documentTitle, $positionLabel, $estimator);
            }
        }

        if (count($queue) === 2) {
            [$first, $last] = $queue;

            if ($this->tokensOf($last) < $options->minTokens) {
                // A stub chunk scores high on similarity while carrying almost
                // no information, so fold its new sentences into the previous
                // window instead of emitting it.
                yield $this->draft($this->mergeTail($first, $last), $ordinal++, $options, $documentTitle, $positionLabel, $estimator);
            } else {
                yield $this->draft($first, $ordinal++, $options, $documentTitle, $positionLabel, $estimator);
                yield $this->draft($last, $ordinal++, $options, $documentTitle, $positionLabel, $estimator);
            }
        } elseif (count($queue) === 1) {
            // A single short document has nothing to merge into.
            yield $this->draft($queue[0], $ordinal, $options, $documentTitle, $positionLabel, $estimator);
        }
    }

    /**
     * The sliding window itself.
     *
     * @param  iterable<int, Sentence>  $sentences
     * @return Generator<int, array<int, Sentence>>
     */
    private function windows(iterable $sentences, ChunkingOptions $options): Generator
    {
        $iterator = $this->toIterator($sentences);

        /** @var array<int, Sentence> $buffer */
        $buffer = [];

        while (true) {
            if ($buffer === [] && ! $this->pull($iterator, $buffer)) {
                return;
            }

            // A single sentence over budget can only be split by force. After
            // SentenceSplitter's hard split this should be unreachable, but a
            // custom estimator could still produce it.
            if ($buffer[0]->tokens > $options->maxTokens) {
                yield [array_shift($buffer)];

                continue;
            }

            $take = 0;
            $tokens = 0;

            while (true) {
                if (! isset($buffer[$take]) && ! $this->pull($iterator, $buffer)) {
                    break;
                }

                if (! isset($buffer[$take])) {
                    break;
                }

                if ($take > 0 && $tokens + $buffer[$take]->tokens > $options->maxTokens) {
                    break;
                }

                $tokens += $buffer[$take]->tokens;
                $take++;

                if ($tokens >= $options->targetTokens) {
                    break;
                }
            }

            if ($take === 0) {
                return;
            }

            yield array_slice($buffer, 0, $take);

            // The window just covered everything that is left, and there is no
            // more input: carrying an overlap forward here would emit a chunk
            // whose every sentence has already been emitted, which costs an
            // embedding and returns as a near-duplicate hit. Short documents
            // hit this on every run, long ones on their last window.
            if ($take === count($buffer) && ! $iterator->valid()) {
                return;
            }

            // Walk back from the end of the window until enough tokens of
            // context are carried forward. `max(1, ...)` is the monotonicity
            // guard: without it a window whose overlap covers itself would
            // never advance.
            $back = 0;
            $cut = $take;

            while ($cut > 1 && $back < $options->overlapTokens) {
                $cut--;
                $back += $buffer[$cut]->tokens;
            }

            array_splice($buffer, 0, max(1, $cut));
        }
    }

    /**
     * @param  \Iterator<int, Sentence>  $iterator
     * @param  array<int, Sentence>  $buffer
     */
    private function pull(\Iterator $iterator, array &$buffer): bool
    {
        if (! $iterator->valid()) {
            return false;
        }

        $buffer[] = $iterator->current();
        $iterator->next();

        return true;
    }

    /**
     * @param  iterable<int, Sentence>  $sentences
     * @return \Iterator<int, Sentence>
     */
    private function toIterator(iterable $sentences): \Iterator
    {
        $iterator = is_array($sentences) ? new \ArrayIterator($sentences) : $sentences;

        if (! $iterator instanceof \Iterator) {
            $iterator = new \IteratorIterator($iterator);
        }

        $iterator->rewind();

        return $iterator;
    }

    /**
     * Append the sentences of $tail that $head does not already contain.
     *
     * @param  array<int, Sentence>  $head
     * @param  array<int, Sentence>  $tail
     * @return array<int, Sentence>
     */
    private function mergeTail(array $head, array $tail): array
    {
        $lastStart = $head[count($head) - 1]->charStart;

        foreach ($tail as $sentence) {
            if ($sentence->charStart > $lastStart) {
                $head[] = $sentence;
            }
        }

        return $head;
    }

    /**
     * @param  array<int, Sentence>  $window
     */
    private function tokensOf(array $window): int
    {
        return array_sum(array_map(static fn (Sentence $s): int => $s->tokens, $window));
    }

    /**
     * @param  array<int, Sentence>  $window
     */
    private function draft(
        array $window,
        int $ordinal,
        ChunkingOptions $options,
        ?string $documentTitle,
        ?callable $positionLabel,
        \Murkrow\FilamentAi\Contracts\TokenEstimator $estimator,
    ): ChunkDraft {
        $first = $window[0];
        $last = $window[count($window) - 1];

        $text = implode(' ', array_map(static fn (Sentence $s): string => $s->text, $window));

        $positionStart = $first->position;
        $positionEnd = max($last->positionEnd, $positionStart);

        $embeddingInput = $text;
        $header = null;

        if ($options->embedContextHeader) {
            $label = $positionLabel !== null
                ? (string) $positionLabel($positionStart, $positionEnd)
                : ($positionStart === $positionEnd ? (string) $positionStart : "{$positionStart}-{$positionEnd}");

            $header = trim(strtr($options->contextHeader, [
                ':document_title' => $documentTitle ?? '',
                ':position_label' => $label,
            ]), " -");

            if ($header === '') {
                $header = null;
            } else {
                $embeddingInput = $header."\n".$text;
            }
        }

        return new ChunkDraft(
            ordinal: $ordinal,
            text: $text,
            embeddingInput: $embeddingInput,
            // Hashing the embedding input rather than the text means a change
            // to the document title also invalidates the stored vector.
            contentHash: hash('sha256', $embeddingInput),
            positionStart: $positionStart,
            positionEnd: $positionEnd,
            charStart: $first->charStart,
            charEnd: $last->charEnd,
            tokenCount: $estimator->count($embeddingInput),
            header: $header,
        );
    }
}
