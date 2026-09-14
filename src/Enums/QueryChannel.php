<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Enums;

enum QueryChannel: string
{
    case Web = 'web';
    case Filament = 'filament';
    case Mcp = 'mcp';
    case Cli = 'cli';
    case Api = 'api';
}
