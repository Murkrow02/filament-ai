<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Answering;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Murkrow\FilamentAi\Contracts\PromptRenderer;
use Murkrow\FilamentAi\Data\Citation;

/**
 * Prompts as publishable Blade views.
 *
 * Prompt wording is the single highest-leverage tuning knob in a RAG system and
 * it is domain-specific -- an archive of OCR'd books needs different guardrails
 * than a support knowledge base. Keeping prompts in views means a host tunes
 * them with `vendor:publish` instead of a fork.
 */
final class BladePromptRenderer implements PromptRenderer
{
    /**
     * @param  Collection<int, Citation>  $citations
     */
    public function context(Collection $citations): string
    {
        return trim(View::make((string) config('filament-ai.answering.context_view', 'filament-ai::prompts.context'), [
            'citations' => $citations,
        ])->render());
    }

    public function system(string $language, ?string $refusalMessage, bool $requireCitations): string
    {
        return trim(View::make((string) config('filament-ai.answering.system_view', 'filament-ai::prompts.system'), [
            'language' => $language,
            'refusalMessage' => $refusalMessage,
            'requireCitations' => $requireCitations,
        ])->render());
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function user(string $question, string $context, array $history = []): string
    {
        return trim(View::make((string) config('filament-ai.answering.user_view', 'filament-ai::prompts.user'), [
            'question' => $question,
            'context' => $context,
            'history' => $history,
        ])->render());
    }
}
