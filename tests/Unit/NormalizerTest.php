<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Chunking\Normalizers\CollapseWhitespace;
use Murkrow\FilamentAi\Chunking\Normalizers\DehyphenateLineBreaks;
use Murkrow\FilamentAi\Chunking\Normalizers\FixOcrLigatures;
use Murkrow\FilamentAi\Chunking\Normalizers\NormalizerPipeline;
use Murkrow\FilamentAi\Chunking\Normalizers\StripControlChars;

it('expands typographic ligatures', function (): void {
    expect((new FixOcrLigatures)->normalize("\u{FB01}renze e \u{FB02}otta"))->toBe('firenze e flotta');
});

it('removes the replacement character OCR emits for unreadable glyphs', function (): void {
    expect((new StripControlChars)->normalize("pa\u{FFFD}rola"))->toBe('parola');
});

it('rejoins a word hyphenated across a line break', function (): void {
    expect((new DehyphenateLineBreaks)->normalize("paro-\nla"))->toBe('parola');
});

it('leaves a hyphen before a capitalised word alone', function (): void {
    expect((new DehyphenateLineBreaks)->normalize("Vittorio-\nEmanuele"))->toContain('-');
});

it('collapses every run of whitespace', function (): void {
    expect((new CollapseWhitespace)->normalize("a  \n\t b   c "))->toBe('a b c');
});

it('applies normalizers in order', function (): void {
    $pipeline = new NormalizerPipeline([
        new StripControlChars,
        new FixOcrLigatures,
        new DehyphenateLineBreaks,
        new CollapseWhitespace,
    ]);

    expect($pipeline->normalize("con\u{FB01}-\nne   spa\u{00A0}ziata"))->toBe('confine spa ziata');
});
