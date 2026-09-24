<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Murkrow\FilamentAi\Filament\Resources\AssistantConversationResource;

class ListAssistantConversations extends ListRecords
{
    protected static string $resource = AssistantConversationResource::class;
}
