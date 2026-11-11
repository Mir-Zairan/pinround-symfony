<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateTicket\CreateTicket;

/**
 * TicketCommands, each command handed to its use case; the contract is on {@see TicketCommands}.
 */
final readonly class UseCaseTicketCommands implements TicketCommands
{
    public function __construct(private CreateTicket $createTicket)
    {
    }

    public function create(
        OrganizationId $organizationId,
        UserId $reporter,
        string $projectId,
        string $title,
        string $description,
        string $type,
        string $priority,
        ?UserId $assignee,
        \DateTimeImmutable $reportedAt,
    ): string {
        return $this->createTicket->create($organizationId, $reporter, $projectId, $title, $description, $type, $priority, $assignee, $reportedAt);
    }
}
