<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\TicketVersionConflict;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTickets::ofKey and save as pinround_app under row-level security, with Acme Foods and Beta Bakery each having
 * a ticket with the same key in a project with the same id.
 */
final class DoctrineTicketsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e721';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e752';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, []);
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::LENA, 'lead', 'developer');
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::OMAR, 'member', 'developer');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function itFindsTheTicketOfTheOrganizationWithTheKey(): void
    {
        $acmeTicketId = $this->createdTicketOf(self::ACME_FOODS);
        $betaTicketId = $this->createdTicketOf(self::BETA_BAKERY);
        $key = $this->keyOf(self::ACME_FOODS, $acmeTicketId);
        self::assertSame($key->toString(), $this->keyOf(self::BETA_BAKERY, $betaTicketId)->toString());

        $found = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => [
            $this->tickets()->ofKey(new OrganizationId(self::BETA_BAKERY), $key)?->id()->toString(),
            $this->tickets()->ofKey(new OrganizationId(self::BETA_BAKERY), TicketKey::fromString('SHOP-999'))?->id()->toString(),
        ]);

        self::assertSame([$betaTicketId, null], $found);
    }

    /**
     * Under Beta Bakery's tenant a lookup that names Acme Foods finds nothing (the tenant filter and row-level security
     * both hide it; the filter-off test below proves row-level security alone does), and under Acme Foods' tenant the
     * SQL's organization alone hides it from a lookup for Beta Bakery, which has no ticket.
     */
    #[Test]
    public function anotherOrganizationsKeyIsNotFound(): void
    {
        $acmeTicketId = $this->createdTicketOf(self::ACME_FOODS);
        $key = $this->keyOf(self::ACME_FOODS, $acmeTicketId);

        $found = [
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key)?->id()->toString()),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::BETA_BAKERY), $key)?->id()->toString()),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key)?->id()->toString()),
        ];

        self::assertSame([null, null, $acmeTicketId], $found);
    }

    /**
     * With the tenant filter off the SQL names only Acme Foods, so under Beta Bakery's tenant nothing but row-level
     * security on tkt_ticket can hide Acme Foods' ticket.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTicketWithTheTenantFilterOff(): void
    {
        $acmeTicketId = $this->createdTicketOf(self::ACME_FOODS);
        $key = $this->keyOf(self::ACME_FOODS, $acmeTicketId);
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);

        $underBetaBakery = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key)?->id()->toString());
        $underAcmeFoods = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key)?->id()->toString());

        self::assertFalse($this->entityManager()->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertNull($underBetaBakery);
        self::assertSame($acmeTicketId, $underAcmeFoods, 'The ticket is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function savingAMovedTicketWritesItsStepAndRaisesItsVersionByOne(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $key = $this->keyOf(self::ACME_FOODS, $ticketId);
        self::assertSame([ShopTeamRows::TO_DO_STEP, 1], $this->stepAndVersionOf($ticketId));

        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($key): void {
            $ticket = $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key);
            self::assertNotNull($ticket);
            $ticket->move(ShopTeamRows::BACKLOG_STEP, 'todo', false, null, new \DateTimeImmutable('2027-03-16 09:30:00 UTC'));
            $this->tickets()->save($ticket);
        });

        self::assertSame([ShopTeamRows::BACKLOG_STEP, 2], $this->stepAndVersionOf($ticketId));
    }

    /**
     * Another request saves the ticket (a raw UPDATE on its own connection raises the version) after this one read it,
     * so this one's save is refused and its move is not written.
     */
    #[Test]
    public function savingATicketAnotherRequestSavedMeanwhileIsRefusedAndWritesNothing(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $key = $this->keyOf(self::ACME_FOODS, $ticketId);

        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key, $ticketId): ?\Throwable {
            $ticket = $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key);
            self::assertNotNull($ticket);
            $this->raiseVersionOnAnotherConnection($ticketId);
            $ticket->move(ShopTeamRows::BACKLOG_STEP, 'todo', false, null, new \DateTimeImmutable('2027-03-16 09:30:00 UTC'));
            try {
                $this->tickets()->save($ticket);
            } catch (\Throwable $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(TicketVersionConflict::class, $refusal);
        self::assertInstanceOf(OptimisticLockException::class, $refusal->getPrevious());
        self::assertSame([ShopTeamRows::TO_DO_STEP, 2], $this->stepAndVersionOf($ticketId));
    }

    #[Test]
    public function savingATicketNotLoadedInThisRequestIsRefusedAndWritesNothing(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $key = $this->keyOf(self::ACME_FOODS, $ticketId);

        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($key): ?\Throwable {
            $ticket = $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key);
            self::assertNotNull($ticket);
            $this->entityManager()->clear();
            $ticket->move(ShopTeamRows::BACKLOG_STEP, 'todo', false, null, new \DateTimeImmutable('2027-03-16 09:30:00 UTC'));
            try {
                $this->tickets()->save($ticket);
            } catch (\LogicException $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertSame('Only a ticket loaded in this request can be saved; a new one is added.', $refusal?->getMessage());
        self::assertSame([ShopTeamRows::TO_DO_STEP, 1], $this->stepAndVersionOf($ticketId));
    }

    /**
     * @return array{string, int}
     */
    private function stepAndVersionOf(string $ticketId): array
    {
        $row = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->entityManager()->getConnection()->fetchAssociative('SELECT step_id, version FROM tkt_ticket WHERE id = ?', [$ticketId]));
        self::assertIsArray($row);
        self::assertIsString($row['step_id']);
        self::assertIsInt($row['version']);

        return [$row['step_id'], $row['version']];
    }

    /**
     * Commits on the migrator's own connection under the tenant already set, so this request's open transaction does
     * not see the change until its UPDATE re-reads the row.
     */
    private function raiseVersionOnAnotherConnection(string $ticketId): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $updatedRows = $migrator->transactional(static fn (Connection $connection): int|string => $connection->executeStatement('UPDATE tkt_ticket SET version = version + 1 WHERE id = ?', [$ticketId]));
        self::assertSame(1, $updatedRows);
    }

    private function createdTicketOf(string $organizationId): string
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return new ShopTickets($ticketCommands, $this->transactions(), $this->entityManager()->getConnection())
            ->createdFor($organizationId, self::SHOP_PROJECT, self::LENA, self::OMAR)
            ->toString();
    }

    private function keyOf(string $organizationId, string $ticketId): TicketKey
    {
        $key = $this->transactions()->rolledBackAs($organizationId, fn (): mixed => $this->entityManager()->getConnection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId]));
        self::assertIsString($key);

        return TicketKey::fromString($key);
    }

    private function tickets(): DoctrineTickets
    {
        return new DoctrineTickets($this->entityManager());
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}
