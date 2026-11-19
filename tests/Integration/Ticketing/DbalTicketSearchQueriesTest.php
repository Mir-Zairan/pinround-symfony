<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Identity\Infrastructure\Doctrine\DbalUserQueries;
use App\Insight\Infrastructure\Doctrine\DbalTurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Workflow\WorkflowRows;
use App\Ticketing\Api\TicketMatch;
use App\Ticketing\Api\TicketMatches;
use App\Ticketing\Api\TicketSearchQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketSearchQueries;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TicketSearchQueries as pinround_app under row-level security. Every ticket is TicketRows' "Checkout
 * button is hidden" for everyone, in its step TICKET_ROWS_STEP, which both organizations have with the same id: Acme
 * Foods has SHOP-1 and SHOP-2, Beta Bakery has SHOP-5, so only the organization boundary keeps them apart.
 */
final class DbalTicketSearchQueriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000b5a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000b5a2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000b5d1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000b5b1';
    private const string BEN = '0197a3c0-0000-7000-8000-00000000b5b2';
    private const string ACME_SHOP_1 = '0197a3c0-0000-7000-8000-00000000b5e1';
    private const string ACME_SHOP_2 = '0197a3c0-0000-7000-8000-00000000b5e2';
    private const string BETA_SHOP_5 = '0197a3c0-0000-7000-8000-00000000b5e5';
    /** The step TicketRows puts every ticket in. */
    private const string TICKET_ROWS_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string SEARCH_TEXT = 'checkout';

    protected function setUp(): void
    {
        $this->removeRows();
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->asTenantMigrator($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                [$organizationId, self::TICKET_ROWS_STEP],
            ));
        }
        $ticketRows = new TicketRows($this->migrator(), $this->tenantContext());
        $ticketRows->addOf(self::ACME_FOODS, self::ACME_SHOP_1, self::SHOP_PROJECT, self::OMAR, 1);
        $ticketRows->addOf(self::ACME_FOODS, self::ACME_SHOP_2, self::SHOP_PROJECT, self::OMAR, 2);
        $ticketRows->addOf(self::BETA_BAKERY, self::BETA_SHOP_5, self::SHOP_PROJECT, self::BEN, 5);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function eachOrganizationFindsOnlyItsOwnOpenMatches(): void
    {
        $acmeMatches = $this->matchesAs(self::ACME_FOODS, self::viewerOf(self::ACME_FOODS, self::OMAR), TicketSearchQueries::MAX_MATCHES);
        $betaMatches = $this->matchesAs(self::BETA_BAKERY, self::viewerOf(self::BETA_BAKERY, self::BEN), TicketSearchQueries::MAX_MATCHES);

        self::assertEqualsCanonicalizing(['SHOP-1', 'SHOP-2'], self::keysOf($acmeMatches));
        self::assertFalse($acmeMatches->hasMore);
        self::assertSame(['SHOP-5'], self::keysOf($betaMatches));
    }

    /**
     * The smallest limit is taken, and the second match only says there is more. Its SQL is recorded, so the refusals
     * below that send none are not an empty recorder.
     */
    #[Test]
    public function aLimitOfOneGivesOneMatchAndSaysThereIsMore(): void
    {
        $viewer = self::viewerOf(self::ACME_FOODS, self::OMAR);

        [$matches, $exception, $sentSql] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->searchedWithSqlOf(
            fn (): TicketMatches => $this->ticketSearchQueries()->openMatchesOf($viewer, self::SEARCH_TEXT, null, 1),
        ));

        self::assertNull($exception);
        self::assertNotNull($matches);
        self::assertCount(1, $matches->tickets);
        self::assertTrue($matches->hasMore);
        self::assertCount(1, array_filter($sentSql, static fn (string $sql): bool => str_contains($sql, 'search_ticket_ids')));
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant is another one: what still hides
     * Acme Foods' tickets from Beta Bakery's transaction is the tenant alone, which row-level security on tkt_ticket and
     * search_ticket_ids' own app.org_id check both read. The steps are Acme Foods', read under Acme Foods, so the search
     * runs on them.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTickets(): void
    {
        $acmeViewer = self::viewerOf(self::ACME_FOODS, self::OMAR);
        self::assertNotSame([], $this->matchesAs(self::ACME_FOODS, $acmeViewer, TicketSearchQueries::MAX_MATCHES)->tickets);
        $acmeSteps = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->stepQueries()->listedSteps());
        self::assertNotSame([], $acmeSteps, 'With no open step the search would never run.');
        $acmeStepQueries = self::createStub(StepQueries::class);
        $acmeStepQueries->method('listedSteps')->willReturn($acmeSteps);

        $matchesUnderAcmeContext = new OwnConnectionTransactions($this->entityManager()->getConnection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): TicketMatches => new DbalTicketSearchQueries(
                $connection,
                $this->tenantContext(),
                $acmeStepQueries,
                new DbalTurnUserQueries($connection, $this->tenantContext()),
                new DbalUserQueries($connection),
            )->openMatchesOf($acmeViewer, self::SEARCH_TEXT, null, TicketSearchQueries::MAX_MATCHES),
        );

        self::assertSame([], $matchesUnderAcmeContext->tickets);
        self::assertFalse($matchesUnderAcmeContext->hasMore);
    }

    /**
     * @return iterable<string, array{string, string|null, int, string}> text, project id, limit and the refusal
     */
    public static function inputsRefusedBeforeAnyQuery(): iterable
    {
        $badText = 'A search text must be trimmed, valid UTF-8 without NUL, and 1 to 200 characters.';
        $badProject = 'A search\'s project id must be a canonical lowercase uuid.';
        $badLimit = 'A search returns 1 to 10 matches.';

        yield 'an empty text' => ['', null, 10, $badText];
        yield 'a text with a space around it' => [' checkout', null, 10, $badText];
        yield 'a text with a NUL character' => ["check\0out", null, 10, $badText];
        yield '201 characters' => [str_repeat('é', 201), null, 10, $badText];
        yield 'an upper case project id' => [self::SEARCH_TEXT, strtoupper(self::SHOP_PROJECT), 10, $badProject];
        yield 'a project id that is no uuid' => [self::SEARCH_TEXT, 'SHOP', 10, $badProject];
        yield 'a limit of 0' => [self::SEARCH_TEXT, null, 0, $badLimit];
        yield 'a limit of 11' => [self::SEARCH_TEXT, null, 11, $badLimit];
    }

    /**
     * In Acme Foods' own transaction with an Acme Foods viewer, so only the input can be refused.
     */
    #[Test]
    #[DataProvider('inputsRefusedBeforeAnyQuery')]
    public function aBadInputIsRefusedBeforeAnyQuery(string $searchText, ?string $projectId, int $limit, string $refusal): void
    {
        $viewer = self::viewerOf(self::ACME_FOODS, self::OMAR);

        [, $exception, $sentSql] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->searchedWithSqlOf(
            fn (): TicketMatches => $this->ticketSearchQueries()->openMatchesOf($viewer, $searchText, $projectId, $limit),
        ));

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame($refusal, $exception->getMessage());
        self::assertSame([], $sentSql);
    }

    #[Test]
    public function aViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $betaViewer = self::viewerOf(self::BETA_BAKERY, self::BEN);

        [, $exception, $sentSql] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->searchedWithSqlOf(
            fn (): TicketMatches => $this->ticketSearchQueries()->openMatchesOf($betaViewer, self::SEARCH_TEXT, null, TicketSearchQueries::MAX_MATCHES),
        ));

        self::assertInstanceOf(\LogicException::class, $exception);
        self::assertSame('Tickets are searched under the viewer\'s own organization as tenant.', $exception->getMessage());
        self::assertSame([], $sentSql);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $viewer = self::viewerOf(self::ACME_FOODS, self::OMAR);

        [, $exception, $sentSql] = $this->searchedWithSqlOf(
            fn (): TicketMatches => $this->ticketSearchQueries()->openMatchesOf($viewer, self::SEARCH_TEXT, null, TicketSearchQueries::MAX_MATCHES),
        );

        self::assertInstanceOf(\LogicException::class, $exception);
        self::assertSame('Tickets are searched in a transaction under the organization as tenant; none is open.', $exception->getMessage());
        self::assertSame([], $sentSql);
    }

    /**
     * Runs the search with the debug holder emptied first.
     *
     * @param \Closure(): TicketMatches $search
     *
     * @return array{TicketMatches|null, \Throwable|null, list<string>} what it returned or threw, and the SQL it sent
     */
    private function searchedWithSqlOf(\Closure $search): array
    {
        $debugData = $this->debugData();
        $debugData->reset();
        $matches = null;
        $exception = null;
        try {
            $matches = $search();
        } catch (\Throwable $thrown) {
            $exception = $thrown;
        }

        return [$matches, $exception, self::sqlOf($debugData)];
    }

    private function matchesAs(string $organizationId, TicketVisibility $viewer, int $limit): TicketMatches
    {
        return $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): TicketMatches => $this->ticketSearchQueries()->openMatchesOf($viewer, self::SEARCH_TEXT, null, $limit),
        );
    }

    private static function viewerOf(string $organizationId, string $userId): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId($organizationId), new UserId($userId), [], false, null);
    }

    /**
     * @return list<string>
     */
    private static function keysOf(TicketMatches $matches): array
    {
        return array_map(static fn (TicketMatch $match): string => $match->key, $matches->tickets);
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asTenantMigrator(string $organizationId, \Closure $work): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function removeRows(): void
    {
        new TicketRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new WorkflowRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function ticketSearchQueries(): TicketSearchQueries
    {
        return self::getContainer()->get(TicketSearchQueries::class);
    }

    private function stepQueries(): StepQueries
    {
        $stepQueries = self::getContainer()->get('test.workflow_step_queries');
        self::assertInstanceOf(StepQueries::class, $stepQueries);

        return $stepQueries;
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
