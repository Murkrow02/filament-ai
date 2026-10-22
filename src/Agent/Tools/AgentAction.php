<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Tools;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Chat\ApprovalCard;
use Murkrow\FilamentAi\Agent\Tools\Contracts\HasApprovalCard;
use Throwable;

/**
 * A base for an application's own write tools: a change the user approves on
 * a card before it runs, like the resource write tools.
 *
 * The work is split in two. `prepare()` resolves and checks the arguments
 * without changing anything -- find the item, check the stock, work out the
 * total -- and returns the card the user decides on; it throws `ToolRefusal`
 * when the request cannot be carried out. `perform()` makes the change.
 *
 * A request `prepare()` refuses is never put to the user: approving a change
 * that cannot be made is a question with no good answer. The model gets the
 * refusal instead and can fix its arguments or explain. `prepare()` runs again
 * right before `perform()`, so a change approved minutes ago is checked
 * against the data as it is now.
 */
abstract class AgentAction extends AgentTool implements Approvable, HasApprovalCard
{
    use InteractsWithApprovals;

    /**
     * Resolve and check the arguments, changing nothing.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolRefusal when the change cannot be made
     */
    abstract protected function prepare(array $arguments): ApprovalCard;

    /**
     * Make the change. Return what the model should report: a sentence, or
     * an array that is sent as JSON (say what was done, e.g. `saved: true`).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<mixed>|string
     */
    abstract protected function perform(array $arguments, ApprovalCard $card): array|string;

    public function approvalCard(array $arguments): ApprovalCard
    {
        try {
            return $this->prepare($arguments);
        } catch (ToolRefusal $refusal) {
            return ApprovalCard::make($this->chatLabel())->summary($refusal->getMessage());
        }
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        if (! $this->available()) {
            return false;
        }

        try {
            $this->prepare($request->all());
        } catch (ToolRefusal) {
            // handle() answers with the refusal and changes nothing.
            return false;
        } catch (Throwable) {
            // So does a failure, through guarded().
            return false;
        }

        return true;
    }

    protected function run(array $arguments): array|string
    {
        return $this->perform($arguments, $this->prepare($arguments));
    }
}
