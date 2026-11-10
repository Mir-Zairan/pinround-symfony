<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The rules of the current organization, for other modules. The reads run under row-level security with no cache, so
 * they need an open transaction whose tenant is the organization.
 */
interface RuleQueries
{
    /**
     * Up to three queries: the step policy, then the team's rule set, then the organization's. Under the step policy
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
