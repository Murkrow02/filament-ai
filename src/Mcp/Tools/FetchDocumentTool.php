<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Murkrow\FilamentAi\Models\Chunk;
use Murkrow\FilamentAi\Models\Document;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * Reads a contiguous span of an indexed document.
 *
 * Search returns isolated passages, which is the right unit for ranking and
 * the wrong one for reading. This is the follow-up call: having found page 47,
 * a client can pull 45-50 and see the argument in context.
 */
class FetchDocumentTool extends Tool
{
    private const MAX_CHARACTERS = 24000;

    public function name(): string
    {
        return (string) config('rag.mcp.tools.fetch.name', 'fetch_document');
    }

    public function description(): string
    {
        return (string) __('rag::rag.mcp.fetch_description');
    }

    public function shouldRegister(): bool
    {
        return (bool) config('rag.mcp.tools.fetch.enabled', true);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()
                ->description('The document identifier, as returned by the search tool or the "documents" resource.')
                ->required(),

            'source' => $schema->string()
                ->enum(app(SourceRegistry::class)->exposedKeys())
                ->description('The source the document belongs to. Only needed when two sources share an identifier.'),

            'position_from' => $schema->integer()
                ->description('First position to read, for example a page number. Defaults to the start of the document.'),

            'position_to' => $schema->integer()
                ->description('Last position to read. Defaults to a short span after position_from.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $externalId = (string) $request->get('document_id', '');

        if ($externalId === '') {
            return Response::error('The "document_id" argument is required.');
        }

        $query = Document::query()->where('external_id', $externalId);

        $source = $request->get('source');

        // Scoped to what the host exposed, always: naming a hidden source
        // must not reach it, and an empty allow-list must reach nothing.
        $exposed = app(SourceRegistry::class)->exposedKeys();

        $query->whereIn('source_key', $source !== null && $source !== ''
            ? array_values(array_intersect($exposed, [(string) $source]))
            : $exposed);

        /** @var Document|null $document */
        $document = $query->first();

        if ($document === null) {
            return Response::error("No indexed document with identifier [{$externalId}].");
        }

        $from = $request->get('position_from');
        $to = $request->get('position_to');

        $chunks = Chunk::query()
            ->where('document_id', $document->id)
            ->overlappingPositions(
                $from === null ? null : (int) $from,
                $to === null ? null : (int) $to,
            )
            ->orderBy('ordinal')
            ->get();

        if ($chunks->isEmpty()) {
            return Response::text('That document has no indexed text in the requested range.');
        }

        $sources = app(SourceRegistry::class);
        $body = '';
        $truncated = false;

        foreach ($chunks as $chunk) {
            $label = $sources->has($document->source_key)
                ? $sources->get($document->source_key)->positionLabel($chunk->position_start, $chunk->position_end)
                : "{$chunk->position_start}-{$chunk->position_end}";

            $block = "--- {$label} ---\n".$chunk->content."\n\n";

            // Adjacent chunks overlap by design, so a long span would otherwise
            // repeat text; the cap keeps the response inside a usable size.
            if (mb_strlen($body) + mb_strlen($block) > self::MAX_CHARACTERS) {
                $truncated = true;
                break;
            }

            $body .= $block;
        }

        $header = ($document->title ?? $document->external_id)." (document_id {$document->external_id})\n\n";
        $footer = $truncated
            ? "\n[Truncated. Request a narrower position range to read further.]"
            : '';

        return Response::text($header.rtrim($body).$footer);
    }
}
