<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Agent\WebSearch\PublicUrlGuard;

it('refuses urls that reach this network, or are not plain http', function (string $url): void {
    expect(PublicUrlGuard::target($url))->toBeNull();
})->with([
    'loopback' => 'http://127.0.0.1/',
    'loopback v6' => 'http://[::1]/',
    'any address' => 'http://0.0.0.0/',
    'private' => 'http://10.0.0.5/',
    'private 172' => 'http://172.16.3.4/',
    'private 192' => 'http://192.168.1.1/',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'carrier-grade nat' => 'http://100.100.100.200/',
    'benchmarking' => 'http://198.18.0.1/',
    'mapped v4' => 'http://[::ffff:127.0.0.1]/',
    'nat64' => 'http://[64:ff9b::a9fe:a9fe]/',
    '6to4' => 'http://[2002:7f00:1::]/',
    'unique local' => 'http://[fd00::1]/',
    'link local v6' => 'http://[fe80::1]/',
    'multicast' => 'http://224.0.0.1/',
    'localhost' => 'http://localhost/',
    'sub localhost' => 'http://app.localhost/',
    'decimal ip' => 'http://2130706433/',
    'octal ip' => 'http://0177.0.0.1/',
    'hex ip' => 'http://0x7f.0.0.1/',
    'short ip' => 'http://127.1/',
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://example.com/',
    'credentials' => 'http://user:pass@example.com/',
    'no host' => 'http:///path',
]);

it('allows a public address and pins it', function (): void {
    $target = PublicUrlGuard::target('https://8.8.8.8/search?q=1');

    expect($target->ip)->toBe('8.8.8.8')
        ->and($target->port)->toBe(443)
        ->and($target->curlResolve())->toBe('8.8.8.8:443:8.8.8.8');

    expect(PublicUrlGuard::target('http://[2606:4700:4700::1111]:8080/')->curlResolve())
        ->toBe('2606:4700:4700::1111:8080:[2606:4700:4700::1111]');
});
