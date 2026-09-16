<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Answering;

use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\Citation;

/**
 * Resolves the "[#n]" markers a grounded answer contains back to the chunks
 * they refer to.
 *
 * Which citations were actually used is the cheapest available signal for
 * tuning top_k: if the model consistently uses three of eight, the other five
 * are paying for context window and latency without contributing.
 */
final class CitationParser
{
    private const MARKER = '/\[#(\d+)\]/';

    /**
     * @param  Collection<int, Citation>  $citations
     * @return Collection<int, Citation>
     */
    public function markUsed(string $answer, Collection $citations): Collection
    {
        $used = $this->markers($answer);

        return $citations->map(
            static fn (Citation $c): Citation => $c->markUsed(in_array($c->marker, $used, true)),
        );
    }

    /**
     * @return array<int, int>
     */
    public function markers(string $answer): array
    {
        preg_match_all(self::MARKER, $answer, $matches);

        return array_values(array_unique(array_map(intval(...), $matches[1] ?? [])));
    }

    /**
     * Strip markers that point at nothing.
     *
     * A model occasionally invents "[#9]" when it was given six sources.
     * Leaving it in the answer implies a source that does not exist.
     *
     * @param  Collection<int, Citation>  $citations
     */
    public function stripUnknownMarkers(string $answer, Collection $citations): string
    {
        $known = $citations->map(static fn (Citation $c): int => $c->marker)->all();

        return (string) preg_replace_callback(
            self::MARKER,
            static fn (array $m): string => in_array((int) $m[1], $known, true) ? $m[0] : '',
            $answer,
        );
    }

    public function hasAnyMarker(string $answer): bool
    {
        return (bool) preg_match(self::MARKER, $answer);
    }
}
