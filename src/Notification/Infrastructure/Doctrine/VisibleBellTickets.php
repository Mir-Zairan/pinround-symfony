<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Bell\BellKind;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The tickets of a person's open bell rows that they may still see (ADR-0013). A notification keeps its ticket after
 * the person leaves the ticket's team, so the bell asks Ticketing on every read instead of joining
 * tkt_ticket, which another module owns. It needs an open transaction under the tenant, as Ticketing's reads do.
 */
final readonly class VisibleBellTickets
{
    /** The open-row filter of BellInbox, on ntf_notification with no alias; DbalBellQueries::BELL is its aliased twin. */
    public const string OPEN_ROWS = 'organization_id = :organization AND user_id = :user AND done_at IS NULL'
        .' AND actor_id IS NOT NULL AND ticket_id IS NOT NULL AND kind IN (:kinds)'
        .' AND (deliver_after IS NULL OR deliver_after <= :now)';

    public const array OPEN_ROW_TYPES = ['kinds' => ArrayParameterType::STRING];

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
    ) {
    }

    /**
     * Two queries besides the person's visibility: the distinct tickets of their open rows, then Ticketing's check.
     * Only the TicketQueries::MAX_CHECKED_TICKET_IDS tickets with the newest open rows are checked; the rows of any
     * older ticket are left out as if hidden, so a bell never shows a ticket nobody checked.
     *
     * @return list<TicketId> none when the person has no open row on a ticket they may see
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set, before any query
     * @throws \LogicException                            when no transaction is open
     */
    public function amongOpenRowsOf(UserId $recipientId, \DateTimeImmutable $now): array
    {
        $parameters = self::openRowParametersOf($this->tenantContext->require()->toString(), $recipientId, $now);
        $ticketIds = $this->connection->fetchFirstColumn(
            'SELECT ticket_id FROM ntf_notification WHERE '.self::OPEN_ROWS
            .' GROUP BY ticket_id ORDER BY max(created_at) DESC, ticket_id LIMIT '.TicketQueries::MAX_CHECKED_TICKET_IDS,
            $parameters,
            self::OPEN_ROW_TYPES,
        );
        if ([] === $ticketIds) {
            return [];
        }

        return $this->ticketQueries->visibleTicketIdsOf(
            $this->ticketVisibilities->of($this->tenantContext->require(), $recipientId),
            array_map(static function (mixed $ticketId): TicketId {
                \assert(\is_string($ticketId));

                return new TicketId($ticketId);
            }, $ticketIds),
        );
    }

    /**
     * @return array<string, mixed> the parameters of OPEN_ROWS
     */
    public static function openRowParametersOf(string $organizationId, UserId $recipientId, \DateTimeImmutable $now): array
    {
        return [
            'organization' => $organizationId,
            'user' => $recipientId->toString(),
            'kinds' => BellKind::codes(),
            'now' => $now->format('Y-m-d H:i:sP'),
        ];
    }
}
