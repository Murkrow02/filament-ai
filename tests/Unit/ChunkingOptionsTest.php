<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Data\ChunkingOptions;

it('produces the same checksum for the same parameters', function (): void {
    $a = ChunkingOptions::fromArray(['target_tokens' => 512, 'overlap_tokens' => 80]);
    $b = ChunkingOptions::fromArray(['target_tokens' => 512, 'overlap_tokens' => 80]);

    expect($a->checksum('m', 1536))->toBe($b->checksum('m', 1536));
});

it('changes the checksum when a chunking parameter changes', function (): void {
    $a = ChunkingOptions::fromArray(['target_tokens' => 512]);
    $b = ChunkingOptions::fromArray(['target_tokens' => 256]);

    expect($a->checksum('m', 1536))->not->toBe($b->checksum('m', 1536));
});

it('changes the checksum when the embedding model or width changes', function (): void {
    $options = ChunkingOptions::fromArray([]);

    expect($options->checksum('model-a', 1536))->not->toBe($options->checksum('model-b', 1536))
        ->and($options->checksum('model-a', 1536))->not->toBe($options->checksum('model-a', 3072));
});

it('round-trips through an array', function (): void {
    $options = ChunkingOptions::fromArray([
        'target_tokens' => 300,
        'overlap_tokens' => 25,
        'context_header' => ':document_title',
    ]);

    expect(ChunkingOptions::fromArray($options->toArray())->checksum('m', 1))
        ->toBe($options->checksum('m', 1));
});
