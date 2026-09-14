<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Sources\FilterInput;

it('parses repeatable cli filters', function (): void {
    $parsed = FilterInput::parseCli(['id_range:1-50', 'title:garibaldi', 'malformed']);

    expect($parsed)->toBe([
        'id_range' => '1-50',
        'title' => 'garibaldi',
    ]);
});

it('keeps colons inside a filter value', function (): void {
    expect(FilterInput::parseCli(['note:a:b']))->toBe(['note' => 'a:b']);
});
