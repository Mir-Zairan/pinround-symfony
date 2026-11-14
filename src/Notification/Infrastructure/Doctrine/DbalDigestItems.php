<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Digest\DigestItems;
use App\Notification\Application\Notify\NotificationKind;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketEmailLabel;
use App\Ticketing\Api\TicketEmailLabels;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * DigestItems on ntf_notification through the unread digest index, under row-level security and with the tenant's
 * organization_id named as well. Besides the person's visibility it runs at most five queries: the window's tickets,
 * Ticketing's visibility check, the listed rows with the window's count, the listed tickets' email labels and their teams'
 * names. Only the TicketEmailLabels::MAX_TICKET_IDS tickets with the newest rows are checked; the rows of any older
 * ticket are left out as if hidden, so an email never names a ticket nobody checked.
 */
final readonly class DbalDigestItems implements DigestItems
{
    private const string WINDOW_ROWS = 'organization_id = :organization AND user_id = :user AND read_at IS NULL'
        .' AND done_at IS NULL AND ticket_id IS NOT NULL AND kind IN (:kinds)'
        .' AND (deliver_after IS NULL OR deliver_after <= :now) AND created_at > :since AND created_at <= :coveredUntil';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
        private TicketEmailLabels $ticketEmailLabels,
        private TeamQueries $teamQueries,
    ) {
    }

    public function digestOf(UserId $recipientId, \DateTimeImmutable $since, \DateTimeImmutable $now): Digest
    {
        $organizationId = $this->tenantContext->require();
        $coveredUntil = Digest::coveredUntilAt($now);
        if ($since >= $coveredUntil) {
            return new Digest([], 0, $coveredUntil);
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A digest is read in a transaction under the organization as tenant; none is open.');
        }
        $parameters = [
            'organization' => $organizationId->toString(),
            'user' => $recipientId->toString(),
            'kinds' => [NotificationKind::Ping->value, NotificationKind::Question->value],
            'now' => $now->format('Y-m-d H:i:sP'),
            'since' => $since->format('Y-m-d H:i:sP'),
            'coveredUntil' => $coveredUntil->format('Y-m-d H:i:sP'),
        ];
        $types = ['kinds' => ArrayParameterType::STRING];

        $windowTicketIds = $this->connection->fetchFirstColumn(
            'SELECT ticket_id FROM ntf_notification WHERE '.self::WINDOW_ROWS
            .' GROUP BY ticket_id ORDER BY max(created_at) DESC, ticket_id LIMIT '.TicketEmailLabels::MAX_TICKET_IDS,
            $parameters,
            $types,
        );
        if ([] === $windowTicketIds) {
            return new Digest([], 0, $coveredUntil);
        }
        $visibleTicketIds = $this->ticketQueries->visibleTicketIdsOf(
            $this->ticketVisibilities->of($organizationId, $recipientId),
            array_map(static fn (mixed $ticketId): TicketId => new TicketId(self::text($ticketId)), $windowTicketIds),
        );
        if ([] === $visibleTicketIds) {
            return new Digest([], 0, $coveredUntil);
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT kind, ticket_id, data->>'actorName' AS actor_name, created_at, count(*) OVER () AS item_count"
            .' FROM ntf_notification WHERE '.self::WINDOW_ROWS.' AND ticket_id IN (:tickets)'
            .' ORDER BY created_at DESC, id DESC LIMIT '.Digest::MAX_LISTED_ITEMS,
            [...$parameters, 'tickets' => array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $visibleTicketIds)],
            [...$types, 'tickets' => ArrayParameterType::STRING],
        );
        $itemCount = \is_int($rows[0]['item_count'] ?? null) ? $rows[0]['item_count'] : 0;
        $tickets = $this->ticketsOf($rows);
        $teamNames = $this->teamNamesOf($tickets);

        return new Digest(array_map(static function (array $row) use ($tickets, $teamNames): DigestItem {
            $ticket = $tickets[self::text($row['ticket_id'])]
                ?? throw new \LogicException('A visible ticket of a digest has no email label.');
            $actorName = $row['actor_name'];
            if (!\is_string($actorName) || '' === $actorName) {
                throw new \UnexpectedValueException('A notification in a digest has no actorName in its data.');
            }

            return new DigestItem(
                $actorName,
                NotificationKind::from(self::text($row['kind'])),
                $ticket->key,
                $ticket->title,
                null === $ticket->title && null !== $ticket->teamId ? $teamNames[$ticket->teamId->toString()] ?? null : null,
                new \DateTimeImmutable(self::text($row['created_at'])),
            );
        }, $rows), $itemCount, $coveredUntil);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, TicketEmailLabel> by ticket id
     */
    private function ticketsOf(array $rows): array
    {
        $ticketIds = array_values(array_unique(array_map(static fn (array $row): string => self::text($row['ticket_id']), $rows)));
        $tickets = [];
        foreach ($this->ticketEmailLabels->labelsOf(array_map(static fn (string $ticketId): TicketId => new TicketId($ticketId), $ticketIds)) as $ticket) {
            $tickets[$ticket->ticketId->toString()] = $ticket;
        }

        return $tickets;
    }

    /**
     * @param array<string, TicketEmailLabel> $tickets
     *
     * @return array<string, string> the names of the restricted tickets' teams, by team id
     */
    private function teamNamesOf(array $tickets): array
    {
        $teamIds = [];
        foreach ($tickets as $ticket) {
            if (null === $ticket->title && null !== $ticket->teamId) {
                $teamIds[$ticket->teamId->toString()] = $ticket->teamId;
            }
        }
        $names = [];
        foreach ($this->teamQueries->summariesOf($this->tenantContext->require(), array_values($teamIds)) as $team) {
            $names[$team->id()->toString()] = $team->name();
        }

        return $names;
    }

    private static function text(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('A digest column from the database is not text.');
        }

        return $value;
    }
}
