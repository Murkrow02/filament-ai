<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Illuminate\Support\Collection;
use Murkrow\FilamentAi\Data\Citation;

interface PromptRenderer
{
    /**
     * @param  Collection<int, Citation>  $citations
     */
    public function context(Collection $citations): string;

    public function system(string $language, ?string $refusalMessage, bool $requireCitations): string;

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function user(string $question, string $context, array $history = []): string;
}
