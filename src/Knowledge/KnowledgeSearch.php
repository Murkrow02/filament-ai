<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Knowledge;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Murkrow\FilamentAi\Agent\Chat\CitedPassages;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Contracts\ScopesDocumentsToUser;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

/**
 * Search and read the indexed corpus on behalf of an external caller.
 *
 * One implementation behind two doors: the MCP server and the in-panel agent
 * both call this, so a fix to scoping or rendering reaches both. Every method
 * takes the list of sources the caller may see and never widens it -- naming a
 * source outside that list reaches nothing, and an empty list reaches nothing.
 */
final class KnowledgeSearch
{
    /**
     * Adjacent chunks overlap by design, so a long span would otherwise repeat
     * text; the cap keeps a fetch inside a usable size.
     */
    public const MAX_FETCH_CHARACTERS = 24000;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly SourceRegistry $sources,
        private readonly CitedPassages $cited,
    ) {}

    /**
     * The sources a request may reach: all allowed ones, or the one it named
     * if that one is allowed. Never more than `$allowed`.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function narrow(array $allowed, ?string $source): array
    {
        if ($source === null || $source === '') {
            return array_values($allowed);
        }

        return array_values(array_intersect($allowed, [$source]));
    }

    /**
     * @param  list<string>  $allowedSources
     * @param  list<string|int>|null  $documentIds  external identifiers
     */
    public function search(
        string $query,
        array $allowedSources,
        ?string $source = null,
        ?array $documentIds = null,
        ?int $positionFrom = null,
        ?int $positionTo = null,
        ?int $limit = null,
        ?float $minScore = null,
    ): KnowledgeResult {
        $query = trim($query);

        if ($query === '') {
            return KnowledgeResult::error('The "query" argument is required.');
        }

        $sourceKeys = self::narrow($allowedSources, $source);

        $result = $this->retriever->retrieve($query, new RetrievalOptions(
            sourceKeys: $sourceKeys,
            externalIds: $documentIds === null || $documentIds === [] ? null : array_map(strval(...), $documentIds),
            positionFrom: $positionFrom,
            positionTo: $positionTo,
            topK: $limit === null ? null : max(1, min(20, $limit)),
            minScore: $minScore,
            constrain: $this->userScope($sourceKeys, 'd'),
        ));

        if ($result->isEmpty()) {
            return KnowledgeResult::text('No passage in the knowledge base matches that query.');
        }

        $chunks = collect($result->chunks)->values()->all();
        $blocks = [];

        // Markers continue across the calls of one turn, so an answer citing
        // "[#4]" points at the fourth passage the assistant read, not at the
        // first result of its second search.
        $offset = $this->cited->offset();

        foreach ($chunks as $index => $chunk) {
            $marker = $offset + $index + 1;

            $blocks[] = $this->renderPassage($marker, $chunk);

            $this->cited->push([
                'label' => ($chunk->documentTitle ?? $chunk->externalId).' - '
                    .$this->positionLabel($chunk->sourceKey, $chunk->positionStart, $chunk->positionEnd),
                'document_id' => (string) $chunk->externalId,
                'score' => round($chunk->score, 2),
                'content' => $chunk->content,
                'url' => $chunk->url,
            ]);
        }

        return new KnowledgeResult(implode("\n\n", $blocks), chunks: $chunks);
    }

    /**
     * @param  list<string>  $allowedSources
     */
    public function fetch(
        string $externalId,
        array $allowedSources,
        ?string $source = null,
        ?int $positionFrom = null,
        ?int $positionTo = null,
    ): KnowledgeResult {
        if ($externalId === '') {
            return KnowledgeResult::error('The "document_id" argument is required.');
        }

        $sourceKeys = self::narrow($allowedSources, $source);
        $documents = Document::query()
            ->where('external_id', $externalId)
            ->whereIn('source_key', $sourceKeys);

        if (($scope = $this->userScope($sourceKeys, $documents->getModel()->getTable())) !== null) {
            $scope($documents);
        }

        $document = $documents->first();

        if ($document === null) {
            return KnowledgeResult::error("No indexed document with identifier [{$externalId}].");
        }

        $chunks = Chunk::query()
            ->where('document_id', $document->id)
            ->overlappingPositions($positionFrom, $positionTo)
            ->orderBy('ordinal')
            ->get();

        if ($chunks->isEmpty()) {
            return KnowledgeResult::text('That document has no indexed text in the requested range.');
        }

        $body = '';
        $truncated = false;

        foreach ($chunks as $chunk) {
            $label = $this->positionLabel($document->source_key, $chunk->position_start, $chunk->position_end);
            $block = "--- {$label} ---\n".$chunk->content."\n\n";

            if (mb_strlen($body) + mb_strlen($block) > self::MAX_FETCH_CHARACTERS) {
                $truncated = true;

                break;
            }

            $body .= $block;
        }

        $header = ($document->title ?? $document->external_id)." (document_id {$document->external_id})\n\n";
        $footer = $truncated
            ? "\n[Truncated. Request a narrower position range to read further.]"
            : '';

        return KnowledgeResult::text($header.rtrim($body).$footer);
    }

    /**
     * What the signed-in user may read, for sources that say so (see
     * `ScopesDocumentsToUser`). Null when no source narrows anything.
     *
     * @param  list<string>  $sourceKeys
     * @return (Closure(Builder<Model>): void)|null
     */
    private function userScope(array $sourceKeys, string $table): ?Closure
    {
        $scoped = [];

        foreach ($sourceKeys as $key) {
            $source = $this->sources->has($key) ? $this->sources->get($key) : null;

            if ($source instanceof ScopesDocumentsToUser) {
                $scoped[$key] = $source;
            }
        }

        if ($scoped === []) {
            return null;
        }

        $user = auth()->user();

        return static function (EloquentBuilder $builder) use ($sourceKeys, $scoped, $user, $table): void {
            $builder->where(static function (EloquentBuilder $any) use ($sourceKeys, $scoped, $user, $table): void {
                foreach ($sourceKeys as $key) {
                    $any->orWhere(static function (EloquentBuilder $one) use ($key, $scoped, $user, $table): void {
                        $one->where("{$table}.source_key", $key);

                        if (! isset($scoped[$key])) {
                            return;
                        }

                        try {
                            $scoped[$key]->scopeDocumentsFor($one->getQuery(), $user, $table);
                        } catch (Throwable $exception) {
                            report($exception);

                            $one->whereRaw('0 = 1');
                        }
                    });
                }
            });
        };
    }

    private function renderPassage(int $marker, ScoredChunk $chunk): string
    {
        $position = $this->positionLabel($chunk->sourceKey, $chunk->positionStart, $chunk->positionEnd);
        $title = $chunk->documentTitle ?? $chunk->externalId;
        $score = number_format($chunk->score, 2);

        return "[#{$marker}] {$title} - {$position} (score {$score}, document_id {$chunk->externalId})\n".$chunk->content;
    }

    private function positionLabel(string $sourceKey, int $start, int $end): string
    {
        return $this->sources->has($sourceKey)
            ? $this->sources->get($sourceKey)->positionLabel($start, $end)
            : "{$start}-{$end}";
    }
}
