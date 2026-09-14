<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * The discovery surface: what is actually in the knowledge base.
 *
 * Without this a client has to guess identifiers before it can scope a search,
 * and guessing identifiers is exactly the kind of thing models do badly. Listed
 * documents also make it obvious when a corpus is only partially embedded.
 */
class DocumentsResource extends Resource
{
    public function name(): string
    {
        return (string) config('rag.mcp.resources.documents.name', 'documents');
    }

    public function description(): string
    {
        return (string) __('rag::rag.mcp.documents_description');
    }

    public function uri(): string
    {
        return 'knowledge://documents';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function shouldRegister(): bool
    {
        return (bool) config('rag.mcp.resources.documents.enabled', true);
    }

    public function handle(): Response
    {
        $registry = app(SourceRegistry::class);
        $exposed = $registry->exposedKeys();

        $query = Document::query()
            ->where('chunk_count', '>', 0)
            ->orderBy('source_key')
            ->orderBy('title');

        $query->whereIn('source_key', $exposed);

        $documents = $query
            ->limit((int) config('rag.mcp.resources.documents.limit', 500))
            ->get();

        $payload = [
            'sources' => array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'label' => app(SourceRegistry::class)->get($key)->label(),
                ],
                $exposed,
            ),
            'documents' => $documents->map(static fn (Document $document): array => [
                'document_id' => $document->external_id,
                'source' => $document->source_key,
                'title' => $document->title,
                'segments' => $document->segment_count,
                'chunks' => $document->chunk_count,
                'coverage' => round($document->coverage(), 3),
                'metadata' => $document->metadata,
            ])->all(),
            'total' => $documents->count(),
        ];

        return Response::json($payload);
    }
}
