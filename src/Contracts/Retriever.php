<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Data\RetrievalResult;

interface Retriever
{
    public function retrieve(string $question, RetrievalOptions $options = new RetrievalOptions): RetrievalResult;
}
