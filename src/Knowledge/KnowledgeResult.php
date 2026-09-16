<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Knowledge;

use Murkrow\FilamentAi\Data\ScoredChunk;

/**
 * What a knowledge lookup produced, independent of who asked.
 *
 * The MCP tools wrap it in a protocol response and the agent tools flatten it
 * into the string laravel/ai hands back to the model; `chunks` is kept so a
 * chat surface can render the passages the agent actually saw.
 */
final readonly class KnowledgeResult
{
    /**
     * @param  list<ScoredChunk>  $chunks
     */
    public function __construct(
        public string $text,
        public bool $isError = false,
        public array $chunks = [],
    ) {}

    public static function error(string $message): self
    {
        return new self($message, isError: true);
    }

    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * The single string a language-model tool call returns. Errors are
     * prefixed rather than thrown: a thrown exception ends the whole turn,
     * while an error the model can read lets it correct its arguments.
     */
    public function toToolOutput(): string
    {
        return $this->isError ? 'Error: '.$this->text : $this->text;
    }
}
