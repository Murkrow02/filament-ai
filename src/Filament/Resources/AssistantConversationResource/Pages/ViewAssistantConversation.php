<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Murkrow\FilamentAi\Agent\Chat\StoredConversation;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource;

/**
 * One conversation, turn by turn, with everything the chat itself leaves out.
 */
class ViewAssistantConversation extends ViewRecord
{
    protected static string $resource = AssistantConversationResource::class;

    protected string $view = 'filament-ai::filament.resources.conversation';

    public function getTitle(): string|Htmlable
    {
        /** @var StoredConversation $record */
        $record = $this->getRecord();

        return filled($record->title) ? (string) $record->title : (string) __('filament-ai::messages.conversations.label');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var StoredConversation $record */
        $record = $this->getRecord();
        $trail = $record->auditTrail();

        return [
            'participant' => $record->participantName(),
            'trail' => $trail,
            'inputTokens' => array_sum(array_map(static fn (array $message): int => (int) $message['input_tokens'], $trail)),
            'outputTokens' => array_sum(array_map(static fn (array $message): int => (int) $message['output_tokens'], $trail)),
        ];
    }
}
