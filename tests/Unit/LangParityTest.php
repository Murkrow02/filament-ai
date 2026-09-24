<?php

declare(strict_types=1);

/**
 * @return array<string, true>
 */
function flatLangKeys(array $messages, string $prefix = ''): array
{
    $keys = [];

    foreach ($messages as $key => $value) {
        $path = $prefix.$key;
        $keys[$path] = true;

        if (is_array($value) && ! array_is_list($value)) {
            $keys += flatLangKeys($value, $path.'.');
        }
    }

    return $keys;
}

it('has the same keys in every language', function (): void {
    $root = dirname(__DIR__, 2).'/resources/lang';
    $en = flatLangKeys(require $root.'/en/messages.php');

    foreach (glob($root.'/*/messages.php') ?: [] as $file) {
        $other = flatLangKeys(require $file);

        expect(array_keys(array_diff_key($en, $other)))->toBe([], "missing in {$file}")
            ->and(array_keys(array_diff_key($other, $en)))->toBe([], "only in {$file}");
    }
});

it('defines every key the code asks for', function (): void {
    $root = dirname(__DIR__, 2);
    $keys = flatLangKeys(require $root.'/resources/lang/en/messages.php');
    $missing = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/views'));

    foreach ([...iterator_to_array($files), ...iterator_to_array($views)] as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        preg_match_all('/filament-ai::messages\\.([A-Za-z0-9_.]*[A-Za-z0-9])(?![A-Za-z0-9_{])/', (string) file_get_contents((string) $file), $matches);

        foreach ($matches[1] as $key) {
            // Keys built at runtime ("ability_{$x}") are skipped by the pattern.
            if (! isset($keys[$key])) {
                $missing[] = $key.' ('.basename((string) $file).')';
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
