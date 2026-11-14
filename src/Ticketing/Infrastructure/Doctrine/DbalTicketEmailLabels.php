<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketEmailLabel;
use App\Ticketing\Api\TicketEmailLabels;
use Doctrine\DBAL\Connection;

final readonly class DbalTicketEmailLabels implements TicketEmailLabels
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function labelsOf(array $ticketIds): array
    {
        if (\count($ticketIds) > self::MAX_TICKET_IDS) {
            throw new \InvalidArgumentException(\sprintf('At most %d ticket labels are read at once.', self::MAX_TICKET_IDS));
        }
        $organizationId = $this->tenantContext->require();
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Ticket email labels are read in a transaction under the organization as tenant; none is open.');
        }
        $uniqueTicketIds = [];
        foreach ($ticketIds as $ticketId) {
            $uniqueTicketIds[$ticketId->toString()] ??= $ticketId;
        }
        if ([] === $uniqueTicketIds) {
            return [];
        }

        // The CASE keeps a restricted ticket's title in the database, so no caller can put it in an email by mistake.
        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a TicketId holds no comma or brace.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, key, team_id, CASE WHEN visibility = 'all' THEN title END AS title FROM tkt_ticket"
            .' WHERE organization_id = CAST(:organization AS uuid) AND id = ANY(CAST(:ids AS uuid[]))',
            ['organization' => $organizationId->toString(), 'ids' => '{'.implode(',', array_keys($uniqueTicketIds)).'}'],
        );
        $labelsById = [];
        foreach ($rows as $row) {
            $ticketId = self::text($row['id'], 'id');
            $teamId = $row['team_id'];
            $title = $row['title'];
            $labelsById[$ticketId] = new TicketEmailLabel(
                new TicketId($ticketId),
                self::text($row['key'], 'key'),
                null === $teamId ? null : new TeamId(self::text($teamId, 'team_id')),
                null === $title ? null : self::text($title, 'title'),
            );
        }

        return array_values(array_filter(array_map(
            static fn (string $ticketId): ?TicketEmailLabel => $labelsById[$ticketId] ?? null,
            array_keys($uniqueTicketIds),
        )));
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The column %s of a ticket is not text.', $column));
        }

        return $value;
    }
}
