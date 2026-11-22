<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * "Where it goes" once WhereItGoesRules::checkedOf found every part among the choices the reporter is offered, in the
 * shape ProblemReport takes.
 */
final readonly class WhereItGoes
{
    /**
     * @param TeamId|null $teamId   null for "Not sure"
     * @param UserId|null $assignee null for "The team (<lead> decides)", and always for "Not sure"
     */
    public function __construct(
        public string $projectId,
        public ?TeamId $teamId,
        public ?UserId $assignee,
        public string $type,
        public string $priority,
    ) {
    }
}
