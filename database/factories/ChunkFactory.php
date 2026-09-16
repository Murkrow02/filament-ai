<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;

/**
 * @extends Factory<Chunk>
 */
class ChunkFactory extends Factory
{
    protected $model = Chunk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'document_id' => Document::factory(),
            'source_key' => 'testing',
            'ordinal' => 0,
            'position_start' => 1,
            'position_end' => 1,
            'char_start' => 0,
            'char_end' => strlen($content),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'token_count' => (int) ceil(mb_strlen($content) / 3.7),
            'metadata' => null,
        ];
    }

    public function embedded(string $model = 'fake-embedding', int $dimensions = 1536): static
    {
        return $this->state([
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedded_at' => now(),
        ]);
    }

    public function spanning(int $from, int $to): static
    {
        return $this->state([
            'position_start' => $from,
            'position_end' => $to,
        ]);
    }
}
