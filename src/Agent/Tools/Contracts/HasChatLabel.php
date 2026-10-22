<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools\Contracts;

/**
 * A host tool that names itself for the person reading the chat ("Cerco il
 * capo") instead of the headline of its tool name ("Find items").
 */
interface HasChatLabel
{
    public function chatLabel(): string;
}
