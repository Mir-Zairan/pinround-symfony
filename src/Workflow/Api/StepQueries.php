<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The steps of the current organization, for other modules. Both reads run under row-level security with no cache, so
 * they need an open transaction whose tenant is the organization; under any other tenant, or none, they find nothing.
 */
interface StepQueries
{
    /**
     * One query. The organization's core and company steps and the team's own steps, without removed ones (a removed
     * step takes no new moves). A team that is unknown or of another organization gets the core and company steps only.
     *
     * @return list<StepView> in list order of the groups (Waiting, Checking, Doing, To do, Done), inside a group core
     *                        steps, then company steps, then team steps, each by position, then by id
     */
    public function stepsFor(TeamId $teamId): array;

    /**
     * The steps a ticket of the team may be moved to: what stepsFor gives, except that under the step policy locked the
     * team's own steps are left out. Under extend and team it equals stepsFor. A ticket already in a team step keeps it;
     * only the choice of a new step changes. Besides the steps it reads the step policy, which needs the tenant of the
     * open transaction; a bad id throws before any query.
     *
     * @param string $teamId a team's uuid, in any case
     *
     * @return list<StepView> in the order of stepsFor
     *
     * @throws \InvalidArgumentException when $teamId is not a canonical UUIDv7
     * @throws \LogicException           when no tenant is set, or the stored step policy is unknown
     */
    public function moveTargetsFor(string $teamId): array;

    /**
     * One query. A removed step still answers, as a ticket may still be in it.
     *
     * @param string $stepId a step's uuid, in any case
     *
     * @return string|null the group code (todo, doing, check, wait or done); null when the organization has no such step
     *
     * @throws \InvalidArgumentException when $stepId is not a canonical UUIDv7, before any query
     */
    public function groupOf(string $stepId): ?string;
}
