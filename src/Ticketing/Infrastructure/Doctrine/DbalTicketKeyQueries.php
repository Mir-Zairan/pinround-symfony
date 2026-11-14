<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketKeyQueries;
use Doctrine\DBAL\Connection;

final readonly class DbalTicketKeyQueries implements TicketKeyQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function keyOf(TicketId $ticketId): ?string
    {
        $key = $this->connection->fetchOne(
            'SELECT key FROM tkt_ticket WHERE organization_id = ? AND id = ?',
            [$this->tenantContext->require()->toString(), $ticketId->toString()],
        );

        return match (true) {
            false === $key => null,
            \is_string($key) => $key,
            default => throw new \LogicException('A ticket key from the database is not text.'),
        };
    }
}
