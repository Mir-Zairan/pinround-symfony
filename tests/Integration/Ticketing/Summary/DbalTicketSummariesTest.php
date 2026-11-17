<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\Summary;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Domain\Summary;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketSummaries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTicketSummaries on the database, under the app's role and row-level security. Acme Foods has SHOP-7; Beta Bakery,
 * another organization, has a ticket of its own.
 */
final class DbalTicketSummariesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f7c01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f7c02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f7c03';
    private const string SHOP_7 = '0197a3c0-0000-7000-8000-0000000f7c04';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000f7c05';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000f7c06';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7c11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f7c12';

    private ?SecondSession $holder = null;
    private ?SecondSession $waiter = null;

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_7, self::SHOP_PROJECT, self::MIA, 7);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::SHOP_PROJECT, self::OMAR, 1);
    }

    protected function tearDown(): void
    {
        foreach ([$this->holder, $this->waiter] as $session) {
            $session?->entityManager->getConnection()->close();
        }
        $this->holder = null;
        $this->waiter = null;
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theFirstSummaryOfATicketBecomesItsCurrentOne(): void
    {
        $isStored = $this->storeAsAcme(self::summaryOf(self::SHOP_7, 'Hidden checkout button', self::MIA, '2027-03-03 10:05:00.900 UTC'));

        self::assertTrue($isStored);
        self::assertSame(
            [['text' => 'Hidden checkout button', 'source' => 'ai', 'author_id' => self::MIA, 'model' => 'claude-sonnet', 'prompt_version' => 'summary.v1', 'is_current' => true, 'created_at' => '2027-03-03 10:05:00']],
            $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7),
        );
    }

    #[Test]
    public function aNewSummaryMovesTheCurrentOneToHistory(): void
    {
        $this->storeAsAcme(self::summaryOf(self::SHOP_7, 'Hidden checkout button', self::MIA, '2027-03-03 10:05:00 UTC'));

        $this->storeAsAcme(self::summaryOf(self::SHOP_7, 'Checkout button hidden on phones', self::OMAR, '2027-03-03 10:20:00 UTC'));

        self::assertSame(
            [['Hidden checkout button', self::MIA, false], ['Checkout button hidden on phones', self::OMAR, true]],
            array_map(static fn (array $row): array => [$row['text'], $row['author_id'], $row['is_current']], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7)),
        );
    }

    #[Test]
    public function aSummaryForATicketTheOrganizationDoesNotHaveWritesNothing(): void
    {
        $this->storeAsAcme(self::summaryOf(self::SHOP_7, 'Hidden checkout button', self::MIA, '2027-03-03 10:05:00 UTC'));

        $isUnknownStored = $this->storeAsAcme(self::summaryOf(self::UNKNOWN_TICKET, 'Unknown ticket', self::MIA, '2027-03-03 10:20:00 UTC'));
        $isBetaStored = $this->storeAsAcme(self::summaryOf(self::BETA_TICKET, 'Beta ticket', self::MIA, '2027-03-03 10:20:00 UTC'));

        self::assertSame([false, false], [$isUnknownStored, $isBetaStored]);
        self::assertSame([], $this->summaryRows()->of(self::BETA_BAKERY, self::BETA_TICKET));
        self::assertSame([], $this->summaryRows()->of(self::ACME_FOODS, self::UNKNOWN_TICKET));
        self::assertSame(
            [['Hidden checkout button', true]],
            array_map(static fn (array $row): array => [$row['text'], $row['is_current']], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7)),
        );
    }

    #[Test]
    public function anotherOrganizationReadsNoSummaryOfThisOneEvenWithNoFilterOfItsOwn(): void
    {
        $this->storeAsAcme(self::summaryOf(self::SHOP_7, 'Hidden checkout button', self::MIA, '2027-03-03 10:05:00 UTC'));
        $countAllAs = fn (string $organizationId): mixed => $this->transactions()->committedAs(
            $organizationId,
            fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM tkt_summary'),
        );

        self::assertSame([1, 0], [$countAllAs(self::ACME_FOODS), $countAllAs(self::BETA_BAKERY)]);
    }

    /**
     * The holder stored a summary and has not committed. The waiter's store must wait for it on the ticket's row, and
     * once the holder commits, a store replaces the holder's summary, so the ticket ends with one current summary.
     */
    #[Test]
    public function twoStoresAtOnceForOneTicketLeaveOneCurrentSummary(): void
    {
        $holderConnection = $this->holder()->entityManager->getConnection();
        $waiterConnection = $this->waiter()->entityManager->getConnection();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $holderConnection->beginTransaction();
        try {
            self::assertTrue(new DbalTicketSummaries($holderConnection, $this->tenantContext())->storeCurrent(
                self::summaryOf(self::SHOP_7, 'Hidden checkout button', self::MIA, '2027-03-03 10:05:00 UTC'),
            ));

            $waiterSummaries = new DbalTicketSummaries($waiterConnection, $this->tenantContext());
            $sqlStateOfStore = $this->waiter()->sqlStateOfFailedAttempt(static function () use ($waiterSummaries): void {
                $waiterSummaries->storeCurrent(self::summaryOf(self::SHOP_7, 'Checkout button hidden on phones', self::OMAR, '2027-03-03 10:05:00 UTC'));
            });
            // With no summary before, a store without the ticket's lock would wait too, on the unique index; this
            // probe takes the ticket's row alone.
            $sqlStateOfTicketLock = $this->waiter()->sqlStateOfFailedAttempt(static function () use ($waiterConnection): void {
                $waiterConnection->fetchOne('SELECT 1 FROM tkt_ticket WHERE id = ? FOR NO KEY UPDATE', [self::SHOP_7]);
            });

            $holderConnection->commit();
        } finally {
            if ($holderConnection->isTransactionActive()) {
                $holderConnection->rollBack();
            }
            $this->tenantContext()->clear();
        }
        $this->storeAsAcme(self::summaryOf(self::SHOP_7, 'Checkout button hidden on phones', self::OMAR, '2027-03-03 10:06:00 UTC'));

        self::assertSame([SecondSession::LOCK_NOT_AVAILABLE, SecondSession::LOCK_NOT_AVAILABLE], [$sqlStateOfStore, $sqlStateOfTicketLock]);
        self::assertSame(
            [['Hidden checkout button', false], ['Checkout button hidden on phones', true]],
            array_map(static fn (array $row): array => [$row['text'], $row['is_current']], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7)),
        );
    }

    #[Test]
    public function aStoreOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->summaries()->storeCurrent(self::summaryOf(self::SHOP_7, 'Hidden checkout button', self::MIA, '2027-03-03 10:05:00 UTC'));
            self::fail('The store ran with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('A summary is stored in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            $this->tenantContext()->clear();
        }

        self::assertSame([], $this->summaryRows()->of(self::ACME_FOODS, self::SHOP_7));
    }

    private function storeAsAcme(Summary $summary): bool
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->summaries()->storeCurrent($summary));
    }

    private static function summaryOf(string $ticketId, string $text, string $requesterId, string $createdAt): Summary
    {
        return Summary::generatedByAi(new TicketId($ticketId), $text, new UserId($requesterId), 'claude-sonnet', 'summary.v1', new \DateTimeImmutable($createdAt));
    }

    private function summaries(): DbalTicketSummaries
    {
        return new DbalTicketSummaries($this->connection(), $this->tenantContext());
    }

    private function holder(): SecondSession
    {
        return $this->holder ??= SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
    }

    private function waiter(): SecondSession
    {
        return $this->waiter ??= SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function summaryRows(): SummaryRows
    {
        return new SummaryRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
