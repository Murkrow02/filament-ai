<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Chunking;

use Murkrow\FilamentAi\Contracts\TokenEstimator;
use Murkrow\FilamentAi\Data\ChunkingOptions;
use Throwable;

/**
 * Builds the estimator a run should use, injecting its characters-per-token
 * ratio when the implementation accepts one.
 *
 * Both the class and the ratio come from ChunkingOptions rather than from
 * config, for two reasons: they are frozen into a run's params_checksum (so
 * changing either correctly marks documents stale), and the chunker stays
 * usable -- and unit-testable -- without a booted application.
 */
final class TokenEstimatorFactory
{
    public function make(ChunkingOptions $options): TokenEstimator
    {
        $class = $options->tokenEstimator !== ''
            ? $options->tokenEstimator
            : HeuristicTokenEstimator::class;

        if (! is_a($class, TokenEstimator::class, true)) {
            $class = HeuristicTokenEstimator::class;
        }

        // Exact BPE counting is opt-in and needs an extra package; degrade to
        // the heuristic rather than failing a run over a token count.
        if ($class === TiktokenEstimator::class && ! TiktokenEstimator::isAvailable()) {
            $class = HeuristicTokenEstimator::class;
        }

        $estimator = $this->instantiate($class);

        if (method_exists($estimator, 'withCharsPerToken')) {
            /** @var TokenEstimator $estimator */
            $estimator = $estimator->withCharsPerToken($options->charsPerToken);
        }

        return $estimator;
    }

    /**
     * @param  class-string<TokenEstimator>  $class
     */
    private function instantiate(string $class): TokenEstimator
    {
        try {
            /** @var TokenEstimator */
            return app($class);
        } catch (Throwable) {
            return new $class;
        }
    }
}
