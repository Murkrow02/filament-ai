<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

/**
 * A host and port, and the one address it was checked at.
 */
final readonly class PinnedTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * The entry for curl's CURLOPT_RESOLVE, which makes the request connect
     * to the checked address while still sending the right Host and SNI.
     */
    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
