<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\WebSearch;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether a url is safe for the *server* to fetch on the model's
 * say-so, and pins the address it may be fetched from.
 *
 * fetch_web_page takes a url the model chose, which may itself have read it
 * from an untrusted page. Without this, "fetch http://169.254.169.254/" or
 * "fetch http://localhost:6379/" turns the tool into an SSRF probe against
 * this application's own network.
 *
 * Checking the name is not enough on its own: the HTTP client would resolve it
 * again, and a record with a short TTL can answer a public address to the
 * check and a private one to the request (DNS rebinding). So `target()`
 * returns the address it checked, and the fetcher connects to exactly that
 * one. Every redirect is checked -- and pinned -- again.
 */
final class PublicUrlGuard
{
    /**
     * Everything that is not the public internet: loopback, private and
     * carrier-grade NAT ranges, link-local (cloud metadata), benchmarking and
     * documentation ranges, multicast, and the IPv6 forms that embed an IPv4
     * address (mapped, compatible, NAT64, 6to4, Teredo).
     */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
        '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function isSafe(string $url): bool
    {
        return self::target($url) !== null;
    }

    public static function target(string $url): ?PinnedTarget
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], strict: true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        // parse_url keeps the brackets of an IPv6 literal.
        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return null;
        }

        // A host made only of digits, dots and hex notation that is not a
        // canonical IP ("0177.0.0.1", "2130706433", "0x7f.1") is refused
        // outright: resolvers and HTTP clients disagree about what it means,
        // and curl reads 0177.0.0.1 as 127.0.0.1 whatever the check resolved.
        if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*\.?$/i', $host) === 1) {
            return null;
        }

        // A literal IP is checked directly; a hostname is resolved and every
        // address it returns must be public -- one private answer among
        // several is still a way in.
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return null;
            }
        }

        return new PinnedTarget(
            host: $host,
            port: (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            ip: $ips[0],
        );
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false && ! IpUtils::checkIp($ip, self::BLOCKED);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
