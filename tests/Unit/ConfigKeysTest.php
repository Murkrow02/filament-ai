<?php

declare(strict_types=1);

it('defines every configuration key the code reads', function (): void {
    $root = dirname(__DIR__, 2);
    $config = ['filament-ai' => require $root.'/config/filament-ai.php'];
    $missing = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        preg_match_all("/config\\(\\s*'(filament-ai\\.[a-z0-9_.]*[a-z0-9_])'/", (string) file_get_contents((string) $file), $matches);

        foreach ($matches[1] as $key) {
            // A key read from config must be declared there, so a host can
            // find it, read what it does and override it.
            if (! \Illuminate\Support\Arr::has($config, $key)) {
                $missing[] = $key.' ('.basename((string) $file).')';
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
