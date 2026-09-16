<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Murkrow\FilamentAi\Enums\DocumentStatus;
use Murkrow\FilamentAi\Models\Document;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_key' => 'testing',
            'external_id' => (string) fake()->unique()->numberBetween(1, 100000),
            'title' => fake()->sentence(4),
            'metadata' => [],
            'segment_count' => 0,
            'chunk_count' => 0,
            'embedded_chunk_count' => 0,
            'token_count' => 0,
            'status' => DocumentStatus::Pending,
        ];
    }

    public function embedded(int $chunks = 3): static
    {
        return $this->state([
            'chunk_count' => $chunks,
            'embedded_chunk_count' => $chunks,
            'status' => DocumentStatus::Embedded,
            'last_ingested_at' => now(),
        ]);
    }
}
