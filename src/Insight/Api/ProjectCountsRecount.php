<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Rebuilds ins_project_counts, the project rail's read model, from the organization's tickets. A ticket counts as done
 * when its step's group is done, as the tickets list's "Show done" splits them, and as open otherwise; a ticket whose
 * step is not the organization's is not counted, as the list shows it in no step.
 */
interface ProjectCountsRecount
{
    /**
     * Replaces the organization's rows in the caller's transaction, under an advisory lock that the same-transaction
     * update of a new ticket waits for, so a ticket created meanwhile is counted once. Safe to run again. Call it after
     * changing a ticket's step, project or visibility outside the commands that keep the counts, such as the demo seed.
     *
     * @throws \LogicException when no transaction is open or the organization is not the transaction's tenant, before
     *                         any write
     */
    public function recount(OrganizationId $organizationId): void;
}
