<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Murkrow\FilamentAi\Data\AnswerResult;
use Murkrow\FilamentAi\Enums\QueryChannel;

final class QueryAnswered
{
    use Dispatchable;

    public function __construct(
        public readonly AnswerResult $result,
        public readonly QueryChannel $channel,
    ) {}
}
