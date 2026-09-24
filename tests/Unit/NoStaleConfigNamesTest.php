<?php

declare(strict_types=1);

it('never names a configuration key by its pre-4.0 name', function (): void {
    $root = dirname(__DIR__, 2);
    $stale = [];

    foreach (['src', 'resources', 'config', 'routes'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! preg_match('/\.(php|js|css)$/', (string) $file)) {
                continue;
            }

            // `config('rag.x')` still reads as a key, and would silently read
            // nothing: the package's keys live under filament-ai.* since 4.0.
            if (preg_match('/(?<![\w\/-])rag\.(agent|chat|llm|embeddings|retrieval|sources|filament|queue|database|chunking|answering|mcp|settings)\b/', (string) file_get_contents((string) $file), $match) === 1) {
                $stale[] = str_replace($root.'/', '', (string) $file).': '.$match[0];
            }
        }
    }

    expect($stale)->toBe([]);
});
