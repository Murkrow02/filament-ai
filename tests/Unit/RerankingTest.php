<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Llm\FakeLanguageModel;
use Murkrow\FilamentAi\Reranking\OllamaReranker;
use Murkrow\FilamentAi\Retrieval\QueryExpander;
use Murkrow\FilamentAi\Retrieval\ReciprocalRankFusion;
use Murkrow\FilamentAi\Retrieval\Lexical\TsVectorLexicalSearch;
use Murkrow\FilamentAi\Support\Excerpt;

it('scores yes against no from the answer log-probabilities', function (): void {
    $likely = OllamaReranker::yesProbability([
        ['token' => 'yes', 'logprob' => -0.4],
        ['token' => 'no', 'logprob' => -1.1],
    ]);

    // A model that answers "no" still ranks by how close "yes" came.
    $closer = OllamaReranker::yesProbability([
        ['token' => 'no', 'logprob' => -0.0001],
        ['token' => 'yes', 'logprob' => -9.3],
    ]);
    $farther = OllamaReranker::yesProbability([
        ['token' => 'no', 'logprob' => -0.0001],
        [' token' => 'ignored'],
        ['token' => 'Yes', 'logprob' => -18.4],
    ]);

    expect($likely)->toBeGreaterThan(0.6)
        ->and($closer)->toBeGreaterThan($farther)
        ->and(OllamaReranker::yesProbability([]))->toBe(0.5);
});

it('excerpts where the query words are, not the start of the passage', function (): void {
    $content = str_repeat('Una lunga introduzione alla fiaba senza nulla di utile. ', 40)
        .'Allora le sorelle dissero: va da Nicola il beccaio e fatti dare mezzo rotolo di trippa.';

    $excerpt = Excerpt::around($content, 'beccaio trippa sorelle', 300);

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(304)
        ->and($excerpt)->toContain('beccaio')
        ->and($excerpt)->toStartWith('… ')
        ->and(Excerpt::around('breve', 'x', 300))->toBe('breve');
});

it('keeps numbers in a query as strings, down to the excerpt', function (): void {
    expect(TsVectorLexicalSearch::terms('Nel 1754 e nel 1799'))->toBe(['nel', '1754', '1799'])
        ->and(Excerpt::around(str_repeat('testo di riempimento ', 40).'accadde nel 1754.', 'anno 1754', 200))
        ->toContain('1754');
});

it('fuses any number of weighted lists by rank', function (): void {
    $fused = (new ReciprocalRankFusion)->fuse([
        ['ids' => [1, 2, 3], 'weight' => 1.0],
        ['ids' => [3, 4], 'weight' => 1.0],
    ], 60);

    expect(array_keys($fused))->toBe([3, 1, 2, 4]);
});

it('reads rewrites from json, drops duplicates of the question and caps them', function (): void {
    $expander = new QueryExpander(new FakeLanguageModel);

    $queries = $expander->parse(
        "Sure:\n{\"queries\": [\"Chi è Giovanni\", \"chi è giovanni\", \"beccaio trippa\", \"  \", \"terza\"]}",
        'Chi è Giovanni',
        1,
    );

    expect($queries)->toBe(['beccaio trippa']);
});

it('falls back to one rewrite per line', function (): void {
    $queries = (new QueryExpander(new FakeLanguageModel))->parse("1. beccaio\n- trippa", 'q', 4);

    expect($queries)->toBe(['beccaio', 'trippa']);
});
