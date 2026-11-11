<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * Adds and removes committed tkt_ticket rows of test organizations through the migrator, which owns the table and so
 * may delete from it (the app may not), under each organization as tenant, as FORCE ROW LEVEL SECURITY applies to the
 * owner too. Removing a ticket removes its ins_ticket_turn, tkt_summary and tkt_ticket_event rows by their ON DELETE
 * CASCADE; removing an organization's tickets then removes its projects, which tkt_ticket_project_fkey kept until then.
 * The caller makes sure no transaction is open.
 */
final readonly class TicketRows
{
    public function __construct(private Connection $migrator, private TenantContext $tenantContext)
    {
    }

    /**
     * A project SHOP with no team for tickets to point at; nothing when the organization already has it.
     */
    public function addProjectOf(string $organizationId, string $projectId): void
    {
        $this->asTenant($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_project (organization_id, id, team_id, key, name, next_number) VALUES (?, ?, NULL, 'SHOP', 'Shop', 1)
             ON CONFLICT (organization_id, id) DO NOTHING",
            [$organizationId, $projectId],
        ));
    }

    /**
     * A bug ticket SHOP-<number> in a To do step of the project, which it adds when missing, so rows of other tables can
     * point at it.
     */
    public function addOf(string $organizationId, string $ticketId, string $projectId, string $reporterId, int $number = 1): void
    {
        $this->addProjectOf($organizationId, $projectId);
        $this->asTenant($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'Checkout button is hidden', '', 'bug', 'major', 2, ?, ?, 'all', '{}', '{}', '2027-03-15 10:00:00+00', '2027-03-15 10:00:00+00')",
            [$organizationId, $ticketId, $projectId, $number, 'SHOP-'.$number, '0197a3c0-0000-7000-8000-00000000f0d0', $reporterId],
        ));
    }

    /**
     * Like addOf, with the visibility, team and people the ticket visibility predicate reads.
     *
     * @param 'all'|'team'                                                                                      $visibility
     * @param array<'assignee_id'|'pending_assignee_id'|'reviewer_id'|'tester_id'|'waiting_on_user_id', string> $otherPeople by column
     */
    public function addWithAudienceOf(string $organizationId, string $ticketId, string $projectId, int $number, string $visibility, ?string $teamId, string $reporterId, array $otherPeople = []): void
    {
        $this->addOf($organizationId, $ticketId, $projectId, $reporterId, $number);
        $people = ['assignee_id' => null, 'pending_assignee_id' => null, 'reviewer_id' => null, 'tester_id' => null, 'waiting_on_user_id' => null, ...$otherPeople];
        $this->asTenant($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET visibility = ?, team_id = ?, assignee_id = ?, pending_assignee_id = ?, reviewer_id = ?, tester_id = ?, waiting_on_user_id = ? WHERE id = ?',
            [$visibility, $teamId, ...array_values($people), $ticketId],
        ));
    }

    public function removeAllOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->asTenant($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM tkt_ticket WHERE organization_id = ?',
                [$organizationId],
            ));
        }
        new ProjectRows($this->migrator, $this->tenantContext)->removeAllOf(...$organizationIds);
    }

    /**
     * @param \Closure(Connection): (int|string) $work
     */
    private function asTenant(string $organizationId, \Closure $work): void
    {
        $this->tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator->transactional($work);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
