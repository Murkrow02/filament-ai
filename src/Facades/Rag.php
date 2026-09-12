<?php

declare(strict_types=1);

namespace Murkrow\Rag\Facades;

use Illuminate\Support\Facades\Facade;
use Murkrow\Rag\RagManager;

/**
 * @method static \Illuminate\Support\Collection<int, \Murkrow\Rag\Data\ScoredChunk> search(string $question, \Murkrow\Rag\Data\RetrievalOptions $options = new \Murkrow\Rag\Data\RetrievalOptions)
 * @method static \Murkrow\Rag\Data\RetrievalResult retrieve(string $question, \Murkrow\Rag\Data\RetrievalOptions $options = new \Murkrow\Rag\Data\RetrievalOptions)
 * @method static \Murkrow\Rag\Data\AnswerResult ask(string $question, \Murkrow\Rag\Data\AnswerOptions $options = new \Murkrow\Rag\Data\AnswerOptions)
 * @method static \Generator stream(string $question, \Murkrow\Rag\Data\AnswerOptions $options = new \Murkrow\Rag\Data\AnswerOptions)
 * @method static \Murkrow\Rag\Models\IngestionRun ingest(string $sourceKey, array $filters = [], \Murkrow\Rag\Enums\IngestionMode $mode = \Murkrow\Rag\Enums\IngestionMode::Incremental, array $chunkingOverrides = [], int|string|null $createdBy = null)
 * @method static \Murkrow\Rag\Models\IngestionRun ingestSync(string $sourceKey, array $filters = [], \Murkrow\Rag\Enums\IngestionMode $mode = \Murkrow\Rag\Enums\IngestionMode::Incremental, array $chunkingOverrides = [], ?\Closure $onProgress = null)
 * @method static \Murkrow\Rag\Data\IngestionEstimate estimate(string $sourceKey, array $filters = [])
 * @method static bool forget(string $sourceKey, string|int $externalId)
 * @method static \Murkrow\Rag\Sources\ClosureKnowledgeSource source(string $key)
 * @method static void register(\Murkrow\Rag\Contracts\KnowledgeSource $source)
 * @method static \Murkrow\Rag\Sources\SourceRegistry sources()
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
