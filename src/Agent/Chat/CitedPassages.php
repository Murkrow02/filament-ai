<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

/**
 * The passages the assistant actually read during one turn.
 *
 * The agent cites them by marker -- "[#2]" -- and a marker is worth nothing
 * unless the reader can see what it points at. The knowledge tool hands its
 * chunks here as it returns them, and the chat renders the list underneath the
 * answer.
 *
 * Collection is opt-in and per turn: the same tool is used by MCP clients and
 * the console, where there is no page to render into and no reason to keep
 * anything in memory.
 *
 * Markers continue across calls within a turn. Two searches that both numbered
 * their results from one would leave the answer citing two different passages
 * as "[#1]".
 */
final class CitedPassages
{
    private bool $collecting = false;

    /** @var list<array<string, mixed>> */
    private array $passages = [];

    /**
     * Start collecting, discarding whatever a previous turn left behind.
     */
    public function collect(): void
    {
        $this->collecting = true;
        $this->passages = [];
    }

    public function stop(): void
    {
        $this->collecting = false;
    }

    public function collecting(): bool
    {
        return $this->collecting;
    }

    /**
     * Where the next tool call should start numbering.
     */
    public function offset(): int
    {
        return $this->collecting ? count($this->passages) : 0;
    }

    /**
     * @param  array<string, mixed>  $passage
     */
    public function push(array $passage): void
    {
        if (! $this->collecting) {
            return;
        }

        $this->passages[] = ['marker' => count($this->passages) + 1] + $passage;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->passages;
    }

    public function count(): int
    {
        return count($this->passages);
    }
}
