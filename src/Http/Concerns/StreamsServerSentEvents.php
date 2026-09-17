<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Concerns;

/**
 * The wire format both chat modes answer in.
 *
 * One vocabulary for the knowledge pipeline and for the agent means the page
 * has one reader: `start`, `delta`, `done` and `error` are common, and the
 * agent adds `tool`, `approval` and `solve` on top. A mode that invented its
 * own events would need its own front end, which is what this package just
 * stopped having.
 */
trait StreamsServerSentEvents
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @return array<string, string>
     */
    protected function eventStreamHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            // nginx buffers proxied responses by default, which turns a stream
            // into one delivery at the end.
            'X-Accel-Buffering' => 'no',
        ];
    }
}
