<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

interface TokenEstimator
{
    public function count(string $text): int;
}
