<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\TicketHistory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * No entity maps tkt_ticket_event (a partitioned table, hidden from the schema diff), so it is written with DBAL; a
 * row past the last monthly partition fails, as the table has no DEFAULT partition.
 */
final readonly class DbalTicketHistory implements TicketHistory
{
    public const string CREATED = 'created';

    public function __construct(private Connection $connection)
    {
    }

    public function recordCreated(OrganizationId $organizationId, TicketId $ticketId, UserId $reporter, string $stepId, \DateTimeImmutable $at): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket event is recorded in the transaction of its change; none is open.');
        }

        $this->connection->executeStatement(
            "INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, from_step, to_step, payload, at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, '{}', ?)",
            [
                Uuid::v7()->toRfc4122(),
                $organizationId->toString(),
                $ticketId->toString(),
                $reporter->toString(),
                self::CREATED,
                $stepId,
                $at->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
