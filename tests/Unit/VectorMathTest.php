<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Embeddings\VectorMath;

it('normalises a vector to unit length', function (): void {
    $normalized = VectorMath::normalize([3.0, 4.0]);

    expect(sqrt($normalized[0] ** 2 + $normalized[1] ** 2))->toEqualWithDelta(1.0, 1e-9);
});

it('leaves a zero vector alone rather than dividing by zero', function (): void {
    expect(VectorMath::normalize([0.0, 0.0]))->toBe([0.0, 0.0]);
});

it('treats the dot product of normalised vectors as cosine similarity', function (): void {
    $a = VectorMath::normalize([1.0, 2.0, 3.0]);
    $b = VectorMath::normalize([2.0, 1.0, 0.5]);

    expect(VectorMath::dot($a, $b))->toEqualWithDelta(VectorMath::cosine($a, $b), 1e-9);
});

it('scores identical vectors at one and opposite vectors at minus one', function (): void {
    expect(VectorMath::cosine([1.0, 0.0], [1.0, 0.0]))->toEqualWithDelta(1.0, 1e-9)
        ->and(VectorMath::cosine([1.0, 0.0], [-1.0, 0.0]))->toEqualWithDelta(-1.0, 1e-9);
});

it('round-trips through the pgvector literal format', function (): void {
    $vector = [0.125, -0.5, 0.0, 1.0];

    $parsed = VectorMath::fromPgLiteral(VectorMath::toPgLiteral($vector));

    expect($parsed)->toHaveCount(4);

    foreach ($vector as $index => $value) {
        expect($parsed[$index])->toEqualWithDelta($value, 1e-7);
    }
});

it('renders a literal pgvector accepts', function (): void {
    expect(VectorMath::toPgLiteral([1.0, 0.5]))->toBe('[1,0.5]');
});
