<?php

declare(strict_types=1);

namespace App\Workflow\Api\Event;

/**
 * A rule was set in the organization's rule set or a team's. Published on every set, also when the rule already had
 * that value: the write cannot tell without an extra read.
 */
final readonly class RulesChanged implements WorkflowEvent
{
    public function __construct(
        public string $organizationId,
        public ?string $teamId,
        public string $ruleCode,
        public bool $isOn,
    ) {
    }
}
