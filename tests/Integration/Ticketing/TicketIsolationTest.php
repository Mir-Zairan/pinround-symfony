<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A ticket and its summary of one organization are out of reach under another organization as tenant, with the tenant
 * filter on and off, for reads and writes alike.
 */
final class TicketIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000033a1';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000033a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000033c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000033d1';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000033e1';
    private const string SMUGGLED_TICKET = '0197a3c0-0000-7000-8000-0000000033e2';
    private const string SUMMARY = '0197a3c0-0000-7000-8000-0000000033e9';
    private const string SMUGGLED_SUMMARY = '0197a3c0-0000-7000-8000-0000000033ea';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000033b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000033b2';
    private const string BACKLOG_STEP = '0197a3c0-0000-7000-8000-0000000033f1';
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-0000000033f2';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->rows()->addProjectOf(self::ACME_FOODS, self::SHOP_PROJECT);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist(Ticket::report(
                new TicketId(self::TICKET),
                new OrganizationId(self::ACME_FOODS),
                new ProjectId(self::SHOP_PROJECT),
                new TicketKey(new ProjectKey('SHOP'), 12),
                'Checkout button is hidden',
                'Open the cart on a small screen.',
                TicketType::Bug,
                Priority::Critical,
                Visibility::Team,
                new UserId(self::LENA),
                new UserId(self::OMAR),
                new TeamId(self::SHOP_TEAM),
                self::BACKLOG_STEP,
                self::TO_DO_STEP,
                new \DateTimeImmutable('2027-03-15 10:00:00+00:00'),
            ));
            $this->entityManager()->flush();
            $this->connection()->executeStatement(
                "INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, is_current, created_at) VALUES (?, ?, ?, 'Hidden on small screens.', 'human', true, now())",
                [self::ACME_FOODS, self::SUMMARY, self::TICKET],
            );
        });
        $this->entityManager()->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function theOrganizationReadsBackTheTicketAsItWasReported(): void
    {
        $columns = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array|false => $this->connection()->fetchAssociative(
            'SELECT key, number, title, type, priority, priority_rank, visibility, step_id, assignee_id, reporter_id, team_id, components, check_reasons, version FROM tkt_ticket WHERE id = ?',
            [self::TICKET],
        ));
        $ticket = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?Ticket => $this->ticketFound());

        self::assertSame(
            [
                'key' => 'SHOP-12', 'number' => 12, 'title' => 'Checkout button is hidden', 'type' => 'bug', 'priority' => 'critical', 'priority_rank' => 1,
                'visibility' => 'team', 'step_id' => self::TO_DO_STEP, 'assignee_id' => self::OMAR, 'reporter_id' => self::LENA, 'team_id' => self::SHOP_TEAM,
                'components' => '{}', 'check_reasons' => '{}', 'version' => 1,
            ],
            $columns,
        );
        self::assertSame(['SHOP-12', Priority::Critical, self::TO_DO_STEP], [$ticket?->key()->toString(), $ticket?->priority(), $ticket?->stepId()]);
    }

    #[Test]
    public function anotherOrganizationFindsNeitherTheTicketNorItsSummary(): void
    {
        [$ticket, $ticketRows, $summaryRows] = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => [
            $this->ticketFound(),
            $this->connection()->fetchFirstColumn('SELECT id FROM tkt_ticket'),
            $this->connection()->fetchFirstColumn('SELECT id FROM tkt_summary'),
        ]);
        $own = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->ticketFound()?->id()->toString(),
            $this->connection()->fetchFirstColumn('SELECT id FROM tkt_summary'),
        ]);

        self::assertSame([null, [], []], [$ticket, $ticketRows, $summaryRows]);
        self::assertSame([self::TICKET, [self::SUMMARY]], $own, 'The rows are not found under their own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function rowLevelSecurityAloneHidesTheTicketFromAnotherOrganizationWithTheTenantFilterOff(): void
    {
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $ticket = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): ?Ticket => $this->ticketFound());
        $this->entityManager()->clear();
        $own = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->ticketFound()?->id()->toString());

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertNull($ticket);
        self::assertSame(self::TICKET, $own, 'The ticket is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function anotherOrganizationCannotAddATicketOrSummaryInTheNameOfTheOrganization(): void
    {
        $smuggledRows = [
            'tkt_ticket' => \sprintf(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank, step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                 VALUES ('%s', '%s', '%s', 13, 'SHOP-13', 'Smuggled', '', 'bug', 'major', 2, '%s', '%s', 'all', '{}', '{}', now(), now())",
                self::ACME_FOODS,
                self::SMUGGLED_TICKET,
                self::SHOP_PROJECT,
                self::TO_DO_STEP,
                self::LENA,
            ),
            'tkt_summary' => \sprintf(
                "INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, is_current, created_at) VALUES ('%s', '%s', '%s', 'Smuggled', 'human', false, now())",
                self::ACME_FOODS,
                self::SMUGGLED_SUMMARY,
                self::TICKET,
            ),
        ];

        foreach ($smuggledRows as $table => $sql) {
            try {
                $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement($sql));
                self::fail(\sprintf('Another organization added a row to %s in the name of Acme Foods.', $table));
            } catch (DriverException $refusal) {
                self::assertSame('42501', $refusal->getSQLState());
                self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $refusal->getMessage());
            }
        }

        self::assertSame(
            [[self::TICKET], [self::SUMMARY]],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->connection()->fetchFirstColumn('SELECT id FROM tkt_ticket'),
                $this->connection()->fetchFirstColumn('SELECT id FROM tkt_summary'),
            ]),
        );
    }

    #[Test]
    public function anotherOrganizationChangesNoTicketOrSummary(): void
    {
        $changedRows = $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn (): array => [
            $this->connection()->executeStatement("UPDATE tkt_ticket SET title = 'Taken' WHERE organization_id = ?", [self::ACME_FOODS]),
            $this->connection()->executeStatement('UPDATE tkt_summary SET is_current = false WHERE organization_id = ?', [self::ACME_FOODS]),
        ]);

        self::assertSame([0, 0], $changedRows);
        self::assertSame(
            ['Checkout button is hidden', true],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->connection()->fetchOne('SELECT title FROM tkt_ticket WHERE id = ?', [self::TICKET]),
                $this->connection()->fetchOne('SELECT is_current FROM tkt_summary WHERE id = ?', [self::SUMMARY]),
            ]),
        );
    }

    private function ticketFound(): ?Ticket
    {
        return $this->entityManager()->find(Ticket::class, ['organizationId' => self::ACME_FOODS, 'id' => self::TICKET]);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
