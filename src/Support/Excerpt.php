<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Support;

use Murkrow\FilamentAi\Retrieval\Lexical\TsVectorLexicalSearch;

/**
 * The part of a long passage that matters to a query: the window of `$limit`
 * characters where the query's words cluster.
 *
 * Cutting a chunk at its start instead loses exactly what matched when the
 * answer sits at its end -- a reranker shown the first 700 characters of a
 * page graded the page that answered the question 0.
 */
final class Excerpt
{
    public static function around(string $content, string $query, int $limit): string
    {
        $length = mb_strlen($content);

        if ($length <= $limit) {
            return $content;
        }

        $limit = max(200, $limit);
        $haystack = mb_strtolower($content);
        $positions = [];

        foreach (TsVectorLexicalSearch::terms($query) as $term) {
            if (mb_strlen($term) < 4) {
                continue;
            }

            // Match on a stem-like prefix, so "sorelle" finds "sorella".
            $needle = mb_substr($term, 0, max(4, mb_strlen($term) - 2));
            $offset = 0;

            while (($found = mb_strpos($haystack, $needle, $offset)) !== false) {
                $positions[] = $found;
                $offset = $found + 1;
            }
        }

        $start = 0;

        if ($positions !== []) {
            sort($positions);
            $best = 0;

            // The window start that covers the most matches.
            foreach ($positions as $candidate) {
                $from = max(0, $candidate - intdiv($limit, 4));
                $covered = count(array_filter($positions, static fn (int $p): bool => $p >= $from && $p < $from + $limit));

                if ($covered > $best) {
                    $best = $covered;
                    $start = $from;
                }
            }
        }

        $start = min($start, max(0, $length - $limit));

        // Back up to a word boundary so no word is cut in half.
        if ($start > 0 && ($space = mb_strrpos(mb_substr($content, 0, $start), ' ')) !== false) {
            $start = $space + 1;
        }

        $excerpt = mb_substr($content, $start, $limit);

        if ($start + $limit < $length && ($space = mb_strrpos($excerpt, ' ')) !== false) {
            $excerpt = mb_substr($excerpt, 0, $space);
        }

        return ($start > 0 ? '… ' : '').trim($excerpt).($start + mb_strlen($excerpt) < $length ? ' …' : '');
    }
}
