<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Murkrow\FilamentAi\Models\Concerns\UsesAiConnection;

/**
 * A folder a person sorts their assistant chats into. Read and written only
 * through `Agent\Chat\ConversationFolders`, which scopes every query to its
 * owner.
 *
 * @property int $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $name
 * @property int $position
 */
class ChatFolder extends Model
{
    use UsesAiConnection;

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
    ];

    protected function aiTableKey(): string
    {
        return 'chat_folders';
    }
}
