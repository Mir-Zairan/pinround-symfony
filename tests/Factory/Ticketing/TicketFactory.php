<?php

declare(strict_types=1);

namespace App\Tests\Factory\Ticketing;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;

/**
 * Makes a valid ticket in one line, through TicketCommands as a person would, so its history row, turn row and outbox
 * event exist too, and commits it. The Ticket aggregate has no public constructor and those rows are not entities, so
 * this is not a Foundry ObjectFactory. The project must belong to a team and the organization needs Backlog and To do steps.
 */
final readonly class TicketFactory
{
    public function __construct(
        private TicketCommands $ticketCommands,
        private TenantTransactions $transactions,
        private Connection $connection,
    ) {
    }

    /**
     * @param array{title?: string, description?: string, type?: string, priority?: string, assignee?: ?string, reportedAt?: \DateTimeImmutable} $attributes
     *
     * @return TicketId the new ticket; its key is "SHOP-n" for the project SHOP
     */
    public function createOne(string $organizationId, string $projectId, string $reporterId, array $attributes = []): TicketId
    {
        $assignee = $attributes['assignee'] ?? null;
        $key = $this->transactions->committedAs($organizationId, fn (): string => $this->ticketCommands->create(
            new OrganizationId($organizationId),
            new UserId($reporterId),
            $projectId,
            $attributes['title'] ?? 'Search box loses focus after typing',
            $attributes['description'] ?? '',
            $attributes['type'] ?? 'bug',
            $attributes['priority'] ?? 'normal',
            null === $assignee ? null : new UserId($assignee),
            $attributes['reportedAt'] ?? new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        ));
        $ticketId = $this->transactions->rolledBackAs($organizationId, fn (): mixed => $this->connection->fetchOne('SELECT id FROM tkt_ticket WHERE key = ?', [$key]));
        if (!\is_string($ticketId)) {
            throw new \LogicException(\sprintf('The ticket %s just created was not found.', $key));
        }

        return new TicketId($ticketId);
    }
}
