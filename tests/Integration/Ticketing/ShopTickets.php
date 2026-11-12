<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;

/**
 * Creates a bug in a project ShopTeamRows added, through TicketCommands as a person would, and commits it.
 */
final readonly class ShopTickets
{
    public function __construct(
        private TicketCommands $ticketCommands,
        private TenantTransactions $transactions,
        private Connection $connection,
    ) {
    }

    public function createdFor(string $organizationId, string $projectId, string $reporterId, string $assigneeId): TicketId
    {
        $key = $this->transactions->committedAs($organizationId, fn (): string => $this->ticketCommands->create(
            new OrganizationId($organizationId),
            new UserId($reporterId),
            $projectId,
            'Cart total shows the wrong sum',
            '',
            'bug',
            'major',
            new UserId($assigneeId),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        ));
        $ticketId = $this->transactions->rolledBackAs($organizationId, fn (): mixed => $this->connection->fetchOne('SELECT id FROM tkt_ticket WHERE key = ?', [$key]));
        if (!\is_string($ticketId)) {
            throw new \LogicException(\sprintf('The ticket %s just created was not found.', $key));
        }

        return new TicketId($ticketId);
    }
}
