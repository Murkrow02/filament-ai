<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Sources;

use Murkrow\FilamentAi\Support\Text;

/**
 * How a chunk's position range is written in a citation: "Pages 12-13" for a
 * span, "Page 12" for a single one.
 */
final readonly class PositionLabels
{
    public function __construct(
        public string $range = ':start-:end',
        public string $single = ':start',
    ) {}

    public function render(int $start, int $end): string
    {
        return Text::positionLabel($start, $end, $this->range, $this->single);
    }
}
