<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;

/**
 * Makes a {@see TicketTurnSnapshot} from a row of tkt_ticket, so the turn query and the ticket page, which reads the
 * same row, build it one way. Pure; it holds no state.
 */
final class TurnSnapshotRow
{
    /** The tkt_ticket columns of the snapshot, for a SELECT whose alias for tkt_ticket is "ticket". */
    public const string COLUMNS = "ticket.project_id, ticket.step_id, ticket.previous_step_id, ticket.team_id, ticket.visibility, ticket.priority,
        ticket.release, ticket.reporter_id, ticket.assignee_id, ticket.pending_assignee_id, ticket.reviewer_id, ticket.tester_id,
        ticket.waiting_on_user_id, ticket.waiting_on_label, 'risk' = ANY (ticket.check_reasons) AS is_risk_check";

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row          the COLUMNS of one ticket
     * @param list<UserId>         $developerIds
     * @param list<UserId>         $testerIds
     *
     * @throws \LogicException when a column has the wrong type
     */
    public static function snapshotOf(
        TicketId $ticketId,
        array $row,
        string $groupCode,
        ?string $coreCode,
        ?UserId $leadId,
        ?string $leadDisplayName,
        array $developerIds,
        array $testerIds,
    ): TicketTurnSnapshot {
        return new TicketTurnSnapshot(
            $ticketId,
            self::text($row['project_id'], 'project_id'),
            self::text($row['step_id'], 'step_id'),
            $coreCode,
            $groupCode,
            self::teamIdOf($row),
            $leadId,
            $leadDisplayName,
            $developerIds,
            $testerIds,
            self::text($row['visibility'], 'visibility'),
            self::text($row['priority'], 'priority'),
            self::textOrNull($row['release'], 'release'),
            new UserId(self::text($row['reporter_id'], 'reporter_id')),
            self::userIdOrNull($row['assignee_id'], 'assignee_id'),
            self::userIdOrNull($row['pending_assignee_id'], 'pending_assignee_id'),
            self::userIdOrNull($row['reviewer_id'], 'reviewer_id'),
            self::userIdOrNull($row['tester_id'], 'tester_id'),
            self::userIdOrNull($row['waiting_on_user_id'], 'waiting_on_user_id'),
            self::textOrNull($row['waiting_on_label'], 'waiting_on_label'),
            self::textOrNull($row['previous_step_id'], 'previous_step_id'),
            true === $row['is_risk_check'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function teamIdOf(array $row): ?TeamId
    {
        return null === $row['team_id'] ? null : new TeamId(self::text($row['team_id'], 'team_id'));
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A ticket %s from the database is not a string.', $column));
    }

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function userIdOrNull(mixed $value, string $column): ?UserId
    {
        return null === $value ? null : new UserId(self::text($value, $column));
    }
}
