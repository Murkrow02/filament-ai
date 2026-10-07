<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

use Murkrow\FilamentAi\Support\Knowledge;

class KnowledgeDisabledException extends FilamentAiException
{
    public function __construct()
    {
        parent::__construct(Knowledge::disabledMessage());
    }
}
