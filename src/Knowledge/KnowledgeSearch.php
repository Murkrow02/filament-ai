<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Knowledge;

use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Agent\Chat\CitedPassages;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\ScoredChunk;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;

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

        $result = $this->retriever->retrieve($query, new RetrievalOptions(
            sourceKeys: self::narrow($allowedSources, $source),
            externalIds: $documentIds === null || $documentIds === [] ? null : array_map(strval(...), $documentIds),
            positionFrom: $positionFrom,
            positionTo: $positionTo,
            topK: $limit === null ? null : max(1, min(20, $limit)),
            minScore: $minScore,
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
                'score' => round($chunk->score, 4),
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

        $document = Document::query()
            ->where('external_id', $externalId)
            ->whereIn('source_key', self::narrow($allowedSources, $source))
            ->first();

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
