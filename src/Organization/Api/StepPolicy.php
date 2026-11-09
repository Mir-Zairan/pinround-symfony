<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * Who may add steps and change the rules, one choice for the whole organization (workflow-engine.md, "Company steps,
 * team steps and the step policy"). The values are those stored in org_settings.data.step_policy.
 */
enum StepPolicy: string
{
    /** Only an admin adds steps; no team steps. */
    case Locked = 'locked';
    /** Company steps plus team steps a lead adds inside a group; the default. */
    case Extend = 'extend';
    /** As Extend, and a lead may change the rules for the team. */
    case Team = 'team';
}
