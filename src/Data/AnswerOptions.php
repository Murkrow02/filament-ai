<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Data;

use Murkrow\FilamentAi\Enums\QueryChannel;

final readonly class AnswerOptions
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function __construct(
        public RetrievalOptions $retrieval = new RetrievalOptions,
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?string $language = null,
        public ?string $systemPrompt = null,
        public array $history = [],
        public QueryChannel $channel = QueryChannel::Api,
        public int|string|null $userId = null,
        public bool $log = true,

        // Set by the chat page so the logged query joins its thread. Every
        // other caller leaves them null and logs exactly as it always has.
        public ?int $conversationId = null,
        public ?int $turn = null,
    ) {}

    public function withRetrieval(RetrievalOptions $retrieval): self
    {
        return new self(
            $retrieval, $this->model, $this->temperature, $this->maxTokens, $this->language,
            $this->systemPrompt, $this->history, $this->channel, $this->userId, $this->log,
            $this->conversationId, $this->turn,
        );
    }

    public function withChannel(QueryChannel $channel): self
    {
        return new self(
            $this->retrieval, $this->model, $this->temperature, $this->maxTokens, $this->language,
            $this->systemPrompt, $this->history, $channel, $this->userId, $this->log,
            $this->conversationId, $this->turn,
        );
    }
}
