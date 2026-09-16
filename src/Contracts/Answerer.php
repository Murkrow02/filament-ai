<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

use Generator;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\AnswerResult;

interface Answerer
{
    public function answer(string $question, AnswerOptions $options = new AnswerOptions): AnswerResult;

    /**
     * Yields text deltas; Generator::getReturn() is the AnswerResult.
     *
     * @return Generator<int, string, mixed, AnswerResult>
     */
    public function stream(string $question, AnswerOptions $options = new AnswerOptions): Generator;
}
