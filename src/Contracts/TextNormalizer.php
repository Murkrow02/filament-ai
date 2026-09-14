<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

interface TextNormalizer
{
    public function normalize(string $text): string;
}
