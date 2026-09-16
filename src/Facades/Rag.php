<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Facades;

use Illuminate\Support\Facades\Facade;
use Murkrow\FilamentAi\RagManager;

/**
 * @method static \Illuminate\Support\Collection<int, \Murkrow\FilamentAi\Data\ScoredChunk> search(string $question, \Murkrow\FilamentAi\Data\RetrievalOptions $options = new \Murkrow\FilamentAi\Data\RetrievalOptions)
 * @method static \Murkrow\FilamentAi\Data\RetrievalResult retrieve(string $question, \Murkrow\FilamentAi\Data\RetrievalOptions $options = new \Murkrow\FilamentAi\Data\RetrievalOptions)
 * @method static \Murkrow\FilamentAi\Data\AnswerResult ask(string $question, \Murkrow\FilamentAi\Data\AnswerOptions $options = new \Murkrow\FilamentAi\Data\AnswerOptions)
 * @method static \Generator stream(string $question, \Murkrow\FilamentAi\Data\AnswerOptions $options = new \Murkrow\FilamentAi\Data\AnswerOptions)
 * @method static \Murkrow\FilamentAi\Models\IngestionRun ingest(string $sourceKey, array $filters = [], \Murkrow\FilamentAi\Enums\IngestionMode $mode = \Murkrow\FilamentAi\Enums\IngestionMode::Incremental, array $chunkingOverrides = [], int|string|null $createdBy = null)
 * @method static \Murkrow\FilamentAi\Models\IngestionRun ingestSync(string $sourceKey, array $filters = [], \Murkrow\FilamentAi\Enums\IngestionMode $mode = \Murkrow\FilamentAi\Enums\IngestionMode::Incremental, array $chunkingOverrides = [], ?\Closure $onProgress = null)
 * @method static \Murkrow\FilamentAi\Data\IngestionEstimate estimate(string $sourceKey, array $filters = [])
 * @method static bool forget(string $sourceKey, string|int $externalId)
 * @method static \Murkrow\FilamentAi\Sources\ClosureKnowledgeSource source(string $key)
 * @method static void register(\Murkrow\FilamentAi\Contracts\KnowledgeSource $source)
 * @method static \Murkrow\FilamentAi\Sources\SourceRegistry sources()
 *
 * @see RagManager
 */
final class Rag extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RagManager::class;
    }
}
