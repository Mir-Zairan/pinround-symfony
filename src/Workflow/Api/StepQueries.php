<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The steps of the current organization, for other modules. stepsFor and moveTargetsFor are cached per team for a day
 * and cleared after a committed change of steps (Infrastructure\Cache\WorkflowCache); a miss reads under row-level
 * security, so they need an open transaction whose tenant is the organization; under any other tenant, or none, they
 * find nothing. With no transaction or tenant they are not cached.
 */
interface StepQueries
{
    /**
     * A hit costs no query; a miss, one. The organization's core and company steps and the team's own steps, without removed ones (a removed
     * step takes no new moves). A team that is unknown or of another organization gets the core and company steps only.
     *
     * @return list<StepView> in list order of the groups (Waiting, Checking, Doing, To do, Done), inside a group core
     *                        steps, then company steps, then team steps, each by position, then by id
     */
    public function stepsFor(TeamId $teamId): array;

    /**
     * The steps a ticket of the team may be moved to: what stepsFor gives, except that under the step policy locked the
     * team's own steps are left out. Under extend and team it equals stepsFor. A ticket already in a team step keeps it;
     * only the choice of a new step changes. It always reads the step policy (one query, as the policy is in the cache
     * key), which needs the tenant of the open transaction; a miss adds the steps query and a second policy read.
     * A bad id throws before any query.
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
     * One query, never cached. A removed step still answers, as a ticket may still be in it.
     *
     * @param string $stepId a step's uuid, in any case
     *
     * @return string|null the group code (todo, doing, check, wait or done); null when the organization has no such step
     *
     * @throws \InvalidArgumentException when $stepId is not a canonical UUIDv7, before any query
     */
    public function groupOf(string $stepId): ?string;
}
