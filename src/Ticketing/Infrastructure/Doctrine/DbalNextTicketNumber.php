<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Application\NextTicketNumber;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\UnknownProject;
use Doctrine\DBAL\Connection;

/**
 * The UPDATE takes the row lock that serializes callers for the same project; row-level security hides another
 * organization's project, so it updates no row then.
 */
final readonly class DbalNextTicketNumber implements NextTicketNumber
{
    public function __construct(private Connection $connection)
    {
    }

    public function takeFor(ProjectId $projectId): int
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Taking a ticket number needs an open transaction.');
        }

        // next_number holds the number the next ticket gets (data-model.md), so the one taken is the value before.
        $takenNumber = $this->connection->fetchOne(
            'UPDATE tkt_project SET next_number = next_number + 1 WHERE id = ? RETURNING next_number - 1',
            [$projectId->toString()],
        );

        if (false === $takenNumber) {
            throw new UnknownProject('No project has this id in the organization.');
        }

        return \is_int($takenNumber) && $takenNumber >= 1
            ? $takenNumber
            : throw new \LogicException('A ticket number from the database is not a positive integer.');
    }
}
