<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The rules of the current organization, for other modules. The read is cached per team for a day and cleared after a
 * committed change of rules (Infrastructure\Cache\WorkflowCache); a miss runs under row-level security, so it needs an
 * open transaction whose tenant is the organization. With no transaction or tenant it is not cached.
 */
interface RuleQueries
{
    /**
     * A hit costs one query, the step policy, which is in the cache key. A miss costs up to four: the step policy (read
     * for the key and again by the database read), the team's rule set and the organization's. Under the step policy
     * team, the team's own rule set when it has one, else the organization's; under extend and locked always the
     * organization's, even if the team has an old row of its own. An organization with no rule set gets all three rules
     * on, as at set-up. A team that is unknown or of another organization gets the organization's rules.
     *
     * @param string $teamId a team's uuid, in any case
     *
     * @throws \InvalidArgumentException when $teamId is not a canonical UUIDv7, before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the stored step policy is unknown
     */
    public function rulesFor(string $teamId): RuleSetView;
}
