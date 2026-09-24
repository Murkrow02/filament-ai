<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Murkrow\FilamentAi\Exceptions\ToolFailedException;
use Throwable;

/**
 * Keeps a failing tool from ending the model's turn, or from handing the
 * provider an exception message.
 *
 * laravel/ai turns an exception thrown by a tool into "The tool call failed: "
 * plus the message, which is sent to the provider and stored with the
 * conversation -- SQL, bound values and file paths included. Every tool runs
 * its body through `guarded()`, which reports the exception with a reference
 * and gives the model a plain `Error:` it can relay instead.
 */
trait GuardsToolFailures
{
    /**
     * @param  callable(): string  $body
     */
    protected function guarded(callable $body): string
    {
        try {
            return $body();
        } catch (AuthorizationException) {
            return 'Error: the current user is not allowed to do this.';
        } catch (Throwable $exception) {
            $reference = Str::lower(Str::random(8));

            report(new ToolFailedException($reference, $this->name(), $exception));

            return "Error: this action failed because of a problem in the application (reference {$reference}). "
                .'Tell the user it did not work and give them the reference; do not retry the same call.';
        }
    }
}
