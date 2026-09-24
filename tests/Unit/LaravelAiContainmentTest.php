<?php

declare(strict_types=1);

it('reads laravel/ai only where invariant 2 allows it', function (): void {
    $root = dirname(__DIR__, 2).'/src';
    $offenders = [];

    foreach (['Http', 'Chat', 'Filament'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (str_ends_with((string) $file, '.php') && str_contains((string) file_get_contents((string) $file), 'Laravel\\Ai\\')) {
                $offenders[] = str_replace($root.'/', '', (string) $file);
            }
        }
    }

    expect($offenders)->toBe([]);
});
