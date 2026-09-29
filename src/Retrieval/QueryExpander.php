<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Retrieval;

use Illuminate\Support\Facades\Cache;
use Murkrow\FilamentAi\Contracts\LanguageModel;
use Throwable;

/**
 * Rewrites a question into the words its answer is likely written in.
 *
 * An embedding of "what did the naive boy's relatives order from the butcher"
 * sits far from a passage about "Giovanni" fetching tripe from "Nicola il
 * beccaio": the question paraphrases, the source names things. One cheap model
 * call turns the paraphrase into concrete restatements, synonym lists (archaic
 * and dialect forms included) and a short passage in the source's own voice;
 * each is retrieved separately and fused with the original by rank.
 *
 * Never fails a search: on any error the question is searched as asked.
 */
final class QueryExpander
{
    /** Bump when the prompt changes, so cached rewrites of the old one are ignored. */
    private const PROMPT_VERSION = 2;

    public function __construct(private readonly LanguageModel $llm) {}

    /**
     * @return list<string> rewrites, never including the question itself
     */
    public function expand(string $question): array
    {
        $question = trim($question);
        $max = max(1, (int) config('filament-ai.retrieval.expansion.max_queries', 4));

        if ($question === '') {
            return [];
        }

        $model = config('filament-ai.retrieval.expansion.model');
        $model = blank($model) ? null : (string) $model;
        $key = 'ai:qx:'.sha1(self::PROMPT_VERSION.'|'.($model ?? $this->llm->model()).'|'.$max.'|'.$this->hint().'|'.$question);
        $ttl = (int) config('filament-ai.retrieval.expansion.cache_ttl', 86400);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->llm->generate($this->systemPrompt($max), $question, $model, 0.0, 500);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $queries = $this->parse($response['text'], $question, $max);

        if ($queries !== []) {
            Cache::put($key, $queries, $ttl);
        }

        return $queries;
    }

    /**
     * @return list<string>
     */
    public function parse(string $text, string $question, int $max): array
    {
        $decoded = null;

        if (preg_match('/\{.*\}/s', $text, $match) === 1) {
            $decoded = json_decode($match[0], true);
        }

        $candidates = is_array($decoded) && isset($decoded['queries']) && is_array($decoded['queries'])
            ? $decoded['queries']
            : preg_split('/\R+/', $text);

        $seen = [mb_strtolower($question) => true];
        $queries = [];

        foreach ((array) $candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim(preg_replace('/^\s*(?:[-*\d.)]+\s+)/u', '', $candidate) ?? '', " \t\"'");

            if ($candidate === '' || mb_strlen($candidate) > 600 || isset($seen[mb_strtolower($candidate)])) {
                continue;
            }

            $seen[mb_strtolower($candidate)] = true;
            $queries[] = $candidate;

            if (count($queries) >= $max) {
                break;
            }
        }

        return $queries;
    }

    private function systemPrompt(int $max): string
    {
        $hint = $this->hint();
        $corpus = $hint === '' ? '' : "\nAbout the collection: {$hint}\n";

        return <<<PROMPT
            You turn a question into search queries for a retrieval system over a document collection
            (books, archives, inscriptions; often OCR text, sometimes old or regional language).{$corpus}
            Questions are often deliberately disguised: every noun may be a periphrasis ("his kinswomen" for
            "his sisters", "the simple lad" for a named fool, "the meat seller" for the local word for butcher).
            The source text uses the plain, concrete, often old-fashioned or dialect words. Your queries must use
            the words the SOURCE would use, never the question's own disguise.

            Write up to {$max} queries, in the language of the question:
            1. The question decoded: each periphrasis replaced by the most likely concrete word or name.
            2. Keywords only: for each key concept, 3 or more synonyms, including archaic, literary, regional and
               dialect forms, and the likely concrete objects involved. No stop words.
            3. One or two sentences narrating the scene as the source book itself would, with those words.
            4. Only if the question is ambiguous: the decoded question under a different interpretation.

            Example, for "Quale bevanda offrì il vecchio avaro ai forestieri smarriti?":
            {"queries": [
              "Quale vino o liquore diede il vecchio tirchio ai viandanti che si erano persi",
              "vino acquavite rosolio liquore bicchiere avaro tirchio spilorcio taccagno viandanti pellegrini forestieri viaggiatori smarriti",
              "Il vecchio, che era spilorcio, offrì ai pellegrini sperduti un bicchiere di vino annacquato."
            ]}

            Do not answer the question and do not explain. Reply with JSON only: {"queries": ["...", "..."]}
            PROMPT;
    }

    private function hint(): string
    {
        return trim((string) config('filament-ai.retrieval.expansion.hint', ''));
    }
}
