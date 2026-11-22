<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Insight\Api\TurnWait;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\LastTicketChange;
use App\Ticketing\Application\MoveTicket\MoveTicket;
use App\Ticketing\Application\ReviewChange;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Domain\MovedFrom;
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
    /** As insertEvent writes at, microseconds kept. */
    private const string AT_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
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
        ?MovedFrom $movedFrom = null,
        ?TurnWait $turnWaitBefore = null,
    ): void {
        $payload = array_filter(['verb' => $verb, 'override_note' => $overrideNote], static fn (?string $value): bool => null !== $value);
        if (null !== $turnWaitBefore) {
            $payload[self::TURN_WAIT_BEFORE_PAYLOAD_KEY] = [
                'user_id' => $turnWaitBefore->userId?->toString(),
                'label' => $turnWaitBefore->label,
                'since' => $turnWaitBefore->since->format(self::AT_FORMAT),
            ];
        }
        if (null !== $movedFrom) {
            $payload[self::MOVED_FROM_PAYLOAD_KEY] = [
                'step_id' => $movedFrom->stepId,
                'previous_step_id' => $movedFrom->previousStepId,
                'waiting_on_user_id' => $movedFrom->waitingOnUserId,
                'waiting_on_label' => $movedFrom->waitingOnLabel,
                'reviewer_id' => $movedFrom->reviewerId,
                'tester_id' => $movedFrom->testerId,
                'closed_at' => $movedFrom->closedAt?->format(self::AT_FORMAT),
            ];
        }
        $this->insertEvent($organizationId, $ticketId, $actor, self::MOVED, $fromStepId, $toStepId, $payload, $at);
    }

    public function lastChangeOf(TicketId $ticketId, \DateTimeImmutable $ticketCreatedAt): ?LastTicketChange
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s history is read in a transaction under the organization as tenant; none is open.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT actor_id, type, to_step, payload -> \''.self::MOVED_FROM_PAYLOAD_KEY.'\' AS moved_from,'
            .' payload -> \''.self::TURN_WAIT_BEFORE_PAYLOAD_KEY.'\' AS turn_wait_before, at FROM tkt_ticket_event'
            .' WHERE organization_id = CAST(:organization AS uuid) AND ticket_id = CAST(:ticket AS uuid) AND at >= CAST(:ticket_created_at AS timestamptz)'
            .' ORDER BY at DESC, id DESC LIMIT 1',
            DbalTicketHistoryEntries::parametersOf($this->tenantContext->require()->toString(), $ticketId, $ticketCreatedAt),
        );
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['actor_id']) || !\is_string($row['at'])) {
            throw new \UnexpectedValueException('A ticket event row lacks its actor or time.');
        }
        $isMove = self::MOVED === $row['type'] && \is_string($row['to_step']);

        return new LastTicketChange(
            new UserId($row['actor_id']),
            $isMove ? $row['to_step'] : null,
            new \DateTimeImmutable($row['at']),
            $isMove && \is_string($row['moved_from']) ? self::movedFromOf($row['moved_from']) : null,
            $isMove && \is_string($row['turn_wait_before']) ? self::turnWaitOf($row['turn_wait_before']) : null,
        );
    }

    /**
     * As movedFromOf, a payload not of the shape recordMoved writes reads as none, so the wait starts at the Undo.
     */
    private static function turnWaitOf(string $json): ?TurnWait
    {
        $fields = json_decode($json, true);
        if (!\is_array($fields) || !\is_string($fields['since'] ?? null)) {
            return null;
        }
        $userId = $fields['user_id'] ?? null;
        $label = $fields['label'] ?? null;
        if (\is_string($userId) === \is_string($label)) {
            return null;
        }

        // Strictly the format recordMoved writes: the constructor would read "last week" as a time.
        $since = \DateTimeImmutable::createFromFormat(self::AT_FORMAT, $fields['since']);
        if (false === $since) {
            return null;
        }

        try {
            return new TurnWait(\is_string($userId) ? new UserId($userId) : null, \is_string($label) ? $label : null, $since);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A payload any later code may have written: one that is not the shape recordMoved writes reads as none, so the move
     * is not undone rather than undone wrong.
     */
    private static function movedFromOf(string $json): ?MovedFrom
    {
        $fields = json_decode($json, true);
        if (!\is_array($fields) || !\is_string($fields['step_id'] ?? null)) {
            return null;
        }
        $textOrNull = static fn (string $key): ?string => \is_string($fields[$key] ?? null) ? $fields[$key] : null;
        $closedAt = $textOrNull('closed_at');

        return new MovedFrom(
            $fields['step_id'],
            $textOrNull('previous_step_id'),
            $textOrNull('waiting_on_user_id'),
            $textOrNull('waiting_on_label'),
            $textOrNull('reviewer_id'),
            $textOrNull('tester_id'),
            null === $closedAt ? null : new \DateTimeImmutable($closedAt),
        );
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
     * @param array<string, string|int|array<string, string|null>|null> $payload
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
                // Microseconds kept: What happened breaks a tie in at by the row id, made when the row is written, and
                // worker-notify can write a review change after a later one of the same second.
                $at->format('Y-m-d\TH:i:s.uP'),
            ],
        );
    }
}
