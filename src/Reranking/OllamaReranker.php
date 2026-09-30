<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Reranking;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Murkrow\FilamentAi\Contracts\Reranker;
use RuntimeException;

/**
 * Pointwise reranking with a local instruction model served by Ollama.
 *
 * Ollama has no rerank endpoint, so each (question, passage) pair is put to an
 * ordinary chat model as a yes/no judgement and scored from the log
 * probabilities of the answer token: P(yes) / (P(yes) + P(no)). A small model
 * that answers "no" to everything still orders passages correctly by how close
 * "yes" came, which is all a reranker needs.
 *
 * Requires an Ollama recent enough to return logprobs from /api/generate, and
 * room for this model next to the embedder: with OLLAMA_MAX_LOADED_MODELS=1 the
 * two evict each other on every search.
 */
final class OllamaReranker implements Reranker
{
    public function isAvailable(): bool
    {
        return $this->url() !== '' && $this->model() !== '';
    }

    public function score(string $question, array $passages): array
    {
        if ($passages === []) {
            return [];
        }

        $concurrency = max(1, (int) $this->option('concurrency', 4));
        $timeout = (int) $this->option('timeout', 60);
        // Title plus the retriever's excerpt; a safety cap only.
        $maxChars = (int) config('filament-ai.retrieval.rerank.max_chars', 1200) + 400;
        $scores = [];

        foreach (array_chunk($passages, $concurrency, true) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $question, $timeout, $maxChars): array {
                $requests = [];

                foreach ($batch as $index => $passage) {
                    $requests[] = $pool->as((string) $index)
                        ->timeout($timeout)
                        ->post($this->url().'/api/generate', [
                            'model' => $this->model(),
                            'prompt' => $this->prompt($question, mb_substr($passage, 0, $maxChars)),
                            'raw' => true,
                            'stream' => false,
                            'logprobs' => true,
                            'top_logprobs' => 20,
                            'keep_alive' => $this->option('keep_alive', '30m'),
                            // One passage and a question fit in 2k tokens; the
                            // model's default window would reserve gigabytes
                            // of memory for a one-token answer.
                            'options' => ['num_predict' => 1, 'temperature' => 0, 'num_ctx' => (int) $this->option('num_ctx', 2048)],
                        ]);
                }

                return $requests;
            });

            foreach ($batch as $index => $passage) {
                $response = $responses[(string) $index] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    throw new RuntimeException('Ollama rerank request failed'
                        .($response instanceof Response ? ' with HTTP '.$response->status() : '').'.');
                }

                $scores[$index] = self::yesProbability((array) $response->json('logprobs.0.top_logprobs', []));
            }
        }

        ksort($scores);

        return array_values($scores);
    }

    /**
     * @param  array<int, mixed>  $topLogprobs
     */
    public static function yesProbability(array $topLogprobs): float
    {
        $yes = null;
        $no = null;

        foreach ($topLogprobs as $entry) {
            if (! is_array($entry) || ! isset($entry['token'], $entry['logprob'])) {
                continue;
            }

            $token = mb_strtolower(trim((string) $entry['token']));
            $logprob = (float) $entry['logprob'];

            if ($token === 'yes' || $token === 'sì' || $token === 'si') {
                $yes = max($yes ?? -INF, $logprob);
            } elseif ($token === 'no') {
                $no = max($no ?? -INF, $logprob);
            }
        }

        // An answer missing from the top list is less likely than the least
        // likely one listed; floor it well below instead of treating it as 0/1.
        $floor = -30.0;
        $yes ??= $floor;
        $no ??= $floor;

        // Sigmoid of the log-odds: stable where exp() of either would underflow.
        return 1.0 / (1.0 + exp($no - $yes));
    }

    private function prompt(string $question, string $passage): string
    {
        $instruction = (string) config(
            'filament-ai.retrieval.rerank.instruction',
            'Judge whether the passage contains the answer to the question, even if it uses different words.',
        );

        return "<|im_start|>system\nJudge whether the Document meets the requirements based on the Query and the Instruct provided. "
            ."Note that the answer can only be \"yes\" or \"no\".<|im_end|>\n"
            ."<|im_start|>user\n<Instruct>: {$instruction}\n<Query>: {$question}\n<Document>: {$passage}<|im_end|>\n"
            ."<|im_start|>assistant\n<think>\n\n</think>\n\n";
    }

    private function url(): string
    {
        $url = $this->option('url') ?: config('ai.providers.ollama.url') ?: 'http://localhost:11434';

        return rtrim((string) $url, '/');
    }

    private function model(): string
    {
        return (string) config('filament-ai.retrieval.rerank.model', '');
    }

    private function option(string $key, mixed $default = null): mixed
    {
        return config("filament-ai.retrieval.rerank.ollama.{$key}", $default);
    }
}
