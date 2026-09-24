<?php

declare(strict_types=1);

/*
 * The chat renders the assistant's Markdown in the browser, with a small
 * renderer of its own (resources/dist/filament-ai-chat.js). Its cases live in
 * tests/js/markdown.cjs and run under node.
 */
it('renders the Markdown a model writes', function (): void {
    exec('command -v node', $found, $missing);

    if ($missing !== 0) {
        $this->markTestSkipped('node is not installed.');
    }

    exec('node '.escapeshellarg(dirname(__DIR__).'/js/markdown.cjs').' 2>&1', $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
});
