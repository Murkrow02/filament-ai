<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Models\Conversation;
use Throwable;

/**
 * An assistant conversation, as the panel's audit pages read it.
 *
 * laravel/ai's model, extended with what those pages need, so nothing under
 * src/Filament has to import laravel/ai (invariant 2).
 *
 * @property string $id
 * @property string|null $title
 * @property string|null $participant_type
 * @property int|string|null $participant_id
 */
class StoredConversation extends Conversation
{
    /**
     * Who wrote it, as the panel names users.
     */
    public function participantName(): string
    {
        try {
            $participant = $this->participant;
        } catch (Throwable) {
            $participant = null;
        }

        if ($participant instanceof Authenticatable && $participant instanceof Model) {
            try {
                return Filament::getUserName($participant);
            } catch (Throwable) {
                return (string) ($participant->getAttribute('name') ?? $participant->getKey());
            }
        }

        return $this->participant_id === null
            ? '-'
            : class_basename((string) $this->participant_type).' #'.$this->participant_id;
    }

    /**
     * Every turn in full: see ConversationTranscript::auditTrail().
     *
     * @return list<array<string, mixed>>
     */
    public function auditTrail(): array
    {
        return app(ConversationTranscript::class)->auditTrail((string) $this->getKey());
    }
}
