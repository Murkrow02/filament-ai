<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools\Contracts;

use Murkrow\FilamentAi\Agent\Chat\ApprovalCard;

/**
 * An approvable host tool that draws its own approval card: what the change
 * is in the application's words, rather than its raw arguments.
 */
interface HasApprovalCard
{
    /**
     * @param  array<string, mixed>  $arguments  the arguments the model called the tool with
     */
    public function approvalCard(array $arguments): ApprovalCard;
}
