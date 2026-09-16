<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Enums\QueryChannel;

it('carries the conversation through its with* helpers', function (): void {
    // Both helpers rebuild the object positionally, so a new constructor
    // argument is dropped silently unless they are updated with it.
    $options = new AnswerOptions(
        channel: QueryChannel::Api,
        conversationId: 42,
        turn: 3,
    );

    $withRetrieval = $options->withRetrieval(new RetrievalOptions(topK: 5));
    $withChannel = $options->withChannel(QueryChannel::Web);

    expect($withRetrieval->conversationId)->toBe(42)
        ->and($withRetrieval->turn)->toBe(3)
        ->and($withChannel->conversationId)->toBe(42)
        ->and($withChannel->turn)->toBe(3)
        ->and($withChannel->channel)->toBe(QueryChannel::Web);
});
