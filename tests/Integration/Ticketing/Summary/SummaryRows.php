<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Summary;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Adds and reads committed tkt_summary rows through the migrator, under each organization as tenant, as FORCE ROW
 * LEVEL SECURITY applies to the owner too. TicketRows::removeAllOf removes them with their tickets. The caller makes
 * sure no transaction is open.
 */
final readonly class SummaryRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    /**
     * A human summary with no model, in the order given.
     */
    public function addOf(string $organizationId, string $summaryId, string $ticketId, string $text, bool $isCurrent, string $createdAt): void
    {
        $this->asTenant($organizationId, static fn (Connection $migrator): mixed => $migrator->executeStatement(
            "INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, author_id, is_current, created_at)
             VALUES (?, ?, ?, ?, 'human', NULL, ?, ?)",
            [$organizationId, $summaryId, $ticketId, $text, $isCurrent ? 'true' : 'false', $createdAt],
        ));
    }

    /**
     * The ticket's summaries oldest first, a tie by id, each time in UTC as "YYYY-MM-DD HH24:MI:SS".
     *
     * @return list<array{text: string, source: string, author_id: ?string, model: ?string, prompt_version: ?string, is_current: bool, created_at: string}>
     */
    public function of(string $organizationId, string $ticketId): array
    {
        /** @var list<array{text: string, source: string, author_id: ?string, model: ?string, prompt_version: ?string, is_current: bool, created_at: string}> $rows */
        $rows = $this->asTenant($organizationId, static fn (Connection $migrator): mixed => $migrator->fetchAllAssociative(
            "SELECT text, source, author_id, model, prompt_version, is_current,
                 to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at
             FROM tkt_summary WHERE ticket_id = ? ORDER BY created_at, id",
            [$ticketId],
        ));

        return $rows;
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asTenant(string $organizationId, \Closure $work): mixed
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        try {
            return $this->migrator->transactional($work);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
