<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Domain\Summary;
use App\Ticketing\Domain\TicketSummaries;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\UuidV7;

/**
 * No entity maps tkt_summary (hidden from the schema diff), so it is written with DBAL.
 */
final readonly class DbalTicketSummaries implements TicketSummaries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function storeCurrent(Summary $summary): bool
    {
        $organizationId = $this->tenantContext->require()->toString();
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A summary is stored in a transaction under the organization as tenant; none is open.');
        }
        $ticketId = $summary->ticketId()->toString();

        // The ticket's row lock orders two stores for one ticket, so the second clears the first's is_current only
        // once it is committed. NO KEY: the FOR KEY SHARE of a row pointing at the ticket need not wait for it.
        $isTicketFound = false !== $this->connection->fetchOne(
            'SELECT 1 FROM tkt_ticket WHERE organization_id = ? AND id = ? FOR NO KEY UPDATE',
            [$organizationId, $ticketId],
        );
        if (!$isTicketFound) {
            return false;
        }

        $this->connection->executeStatement(
            'UPDATE tkt_summary SET is_current = false WHERE organization_id = ? AND ticket_id = ? AND is_current',
            [$organizationId, $ticketId],
        );
        $createdAt = $summary->createdAt();
        $this->connection->executeStatement(
            'INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, author_id, model, prompt_version, is_current, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, true, ?)',
            [
                $organizationId,
                new UuidV7(UuidV7::generate($createdAt))->toRfc4122(),
                $ticketId,
                $summary->text(),
                $summary->source()->value,
                $summary->authorId()?->toString(),
                $summary->model(),
                $summary->promptVersion(),
                // Whole seconds, as the column keeps: PostgreSQL would round a fraction, maybe into the next second.
                $createdAt->format('Y-m-d H:i:sP'),
            ],
        );

        return true;
    }
}
