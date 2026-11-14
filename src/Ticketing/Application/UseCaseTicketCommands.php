<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateTicket\CreateTicket;
use App\Ticketing\Application\MoveTicket\MoveTicket;

/**
 * TicketCommands, each command handed to its use case; the contract is on {@see TicketCommands}.
 */
final readonly class UseCaseTicketCommands implements TicketCommands
{
    public function __construct(private CreateTicket $createTicket, private MoveTicket $moveTicket)
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

    public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at): int
    {
        return $this->moveTicket->move($organizationId, $actor, $key, $verb, $expectedVersion, $at);
    }

    public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): int
    {
        return $this->moveTicket->giveTo($organizationId, $actor, $key, $assignee, $expectedVersion, $at);
    }

    public function moveTo(
        OrganizationId $organizationId,
        UserId $actor,
        string $key,
        string $stepId,
        int $expectedVersion,
        ?UserId $waitingOnUserId,
        ?string $waitingOnLabel,
        \DateTimeImmutable $at,
    ): int {
        return $this->moveTicket->moveTo($organizationId, $actor, $key, $stepId, $expectedVersion, $waitingOnUserId, $waitingOnLabel, $at);
    }
}
