<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\MoveTicket\MoveTicket;
use App\Ticketing\Application\ReviewChange;
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
    public const string MOVED = 'moved';

    public function __construct(private Connection $connection)
    {
    }

    public function recordCreated(OrganizationId $organizationId, TicketId $ticketId, UserId $reporter, string $stepId, \DateTimeImmutable $at): void
    {
        $this->insertEvent($organizationId, $ticketId, $reporter, self::CREATED, null, $stepId, [], $at);
    }

    public function recordMoved(
        OrganizationId $organizationId,
        TicketId $ticketId,
        UserId $actor,
        string $fromStepId,
        string $toStepId,
        ?string $verb,
        ?string $overrideNote,
        \DateTimeImmutable $at,
    ): void {
        $payload = array_filter(['verb' => $verb, 'override_note' => $overrideNote], static fn (?string $value): bool => null !== $value);
        $this->insertEvent($organizationId, $ticketId, $actor, self::MOVED, $fromStepId, $toStepId, $payload, $at);
    }

    public function recordGiven(
        OrganizationId $organizationId,
        TicketId $ticketId,
        UserId $actor,
        string $fromStepId,
        string $toStepId,
        UserId $assignee,
        \DateTimeImmutable $at,
    ): void {
        $payload = ['verb' => MoveTicket::GIVE_IT_TO, self::GIVEN_TO_PAYLOAD_KEY => $assignee->toString()];
        $this->insertEvent($organizationId, $ticketId, $actor, self::MOVED, $fromStepId, $toStepId, $payload, $at);
    }

    public function recordReviewChange(OrganizationId $organizationId, TicketId $ticketId, UserId $actor, ReviewChange $change, \DateTimeImmutable $at): void
    {
        $payload = ['outcome' => $change->outcome, 'pin_number' => $change->pinNumber, 'area_name' => $change->areaName];
        $this->insertEvent($organizationId, $ticketId, $actor, $change->kind, null, null, $payload, $at);
    }

    /**
     * @param array<string, string|int|null> $payload
     */
    private function insertEvent(
        OrganizationId $organizationId,
        TicketId $ticketId,
        UserId $actor,
        string $type,
        ?string $fromStepId,
        ?string $toStepId,
        array $payload,
        \DateTimeImmutable $at,
    ): void {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket event is recorded in the transaction of its change; none is open.');
        }

        $this->connection->executeStatement(
            'INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, from_step, to_step, payload, at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::v7()->toRfc4122(),
                $organizationId->toString(),
                $ticketId->toString(),
                $actor->toString(),
                $type,
                $fromStepId,
                $toStepId,
                json_encode((object) $payload, \JSON_THROW_ON_ERROR),
                $at->format(\DateTimeInterface::ATOM),
            ],
        );
    }
}
