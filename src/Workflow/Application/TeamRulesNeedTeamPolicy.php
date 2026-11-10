<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * A team's own rule was set while the organization's step policy is not team, under which only the organization's
 * rules apply.
 */
final class TeamRulesNeedTeamPolicy extends \DomainException
{
}
