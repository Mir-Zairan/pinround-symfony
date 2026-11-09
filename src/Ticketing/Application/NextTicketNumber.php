<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\UnknownProject;

/**
 * Hands out the numbers of a project's tickets: 1, 2, 3 and so on, each once, without gaps between committed
 * tickets (FR-ORG-06, ADR-0006).
 */
interface NextTicketNumber
{
    /**
     * Must run inside an open transaction under the project's organization as tenant, the one that saves the
     * ticket. It locks the project's row until that transaction ends, so a second caller for the same project
     * waits and then gets the next number; a rollback gives the number back.
     *
     * @return positive-int
     *
     * @throws UnknownProject  when the project is not found, also when it belongs to another organization
     * @throws \LogicException when no transaction is open, as the lock would end with the statement
     */
    public function takeFor(ProjectId $projectId): int;
}
