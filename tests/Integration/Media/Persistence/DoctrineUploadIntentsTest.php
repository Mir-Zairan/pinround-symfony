<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntentUnusable;
use App\Media\Domain\UploadPurpose;
use App\Media\Infrastructure\Doctrine\DoctrineUploadIntents;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * DoctrineUploadIntents on the real med_upload_intent table as pinround_app, under row-level security. The clock
 * stands at 2026-03-02 10:00 UTC; the intents expire a quarter of an hour later unless a test says otherwise.
 */
final class DoctrineUploadIntentsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1401';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000e1402';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1403';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e1404';
    private const string ACME_INTENT = '0197a3c0-0000-7000-8000-0000000e1411';
    private const string OTHER_INTENT = '0197a3c0-0000-7000-8000-0000000e1412';
    private const string NOW = '2026-03-02 10:00:00+00:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function anAddedIntentIsReadBackWithAllItsValues(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?UploadIntent => $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT)));

        self::assertNotNull($found);
        self::assertSame(self::ACME_FOODS, $found->organizationId()->toString());
        self::assertSame(self::MIA, $found->userId()->toString());
        self::assertSame(10_485_760, $found->maxBytes());
        self::assertSame(['image/png', 'image/webp'], $found->allowedMimeTypes());
        self::assertSame('acme/'.self::ACME_INTENT, $found->storageKey());
        self::assertEquals(new \DateTimeImmutable('2026-03-02 10:15:00+00:00'), $found->expiresAt());
        self::assertNull($found->usedAt());
    }

    #[Test]
    public function theIntentOfAnotherOrganizationOrAnUnknownIdIsNotFound(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');
        $this->addIntent(self::OTHER_ORGANIZATION, self::OTHER_INTENT, self::OMAR, '2026-03-02 10:15:00+00:00');

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT))?->storageKey(),
            $this->uploadIntents()->byId(new UploadIntentId(self::OTHER_INTENT)),
            $this->uploadIntents()->byId(new UploadIntentId('0197a3c0-0000-7000-8000-0000000e1419')),
        ]);

        self::assertSame(['acme/'.self::ACME_INTENT, null, null], $found);
    }

    #[Test]
    public function recordUseMarksTheIntentUsedInTheTableAndInMemory(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');

        $usedAtInMemory = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\DateTimeImmutable {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            $this->uploadIntents()->recordUse($intent);

            return $intent->usedAt();
        });

        self::assertEquals(new \DateTimeImmutable(self::NOW), $usedAtInMemory);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->storedUsedAt(self::ACME_INTENT));
    }

    #[Test]
    public function aSecondUseIsRefusedAndKeepsTheFirstUseTime(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            $this->uploadIntents()->recordUse($intent);
        });
        $this->clock->modify('+1 minute');

        $usedAtInMemory = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\DateTimeImmutable {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            try {
                $this->uploadIntents()->recordUse($intent);
                self::fail('A used upload intent was used again.');
            } catch (UploadIntentUnusable $refusal) {
                self::assertSame('The upload intent is used already.', $refusal->getMessage());
            }

            return $intent->usedAt();
        });

        self::assertEquals(new \DateTimeImmutable(self::NOW), $usedAtInMemory);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->storedUsedAt(self::ACME_INTENT));
    }

    /**
     * Two requests read the intent unused; the one that writes second is refused although its copy still says unused.
     */
    #[Test]
    public function aUseByAnotherRequestSinceTheIntentWasReadIsRefusedAndLeavesTheCopyAsItWas(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');
        $otherRequest = $this->otherSession();

        $usedAtInMemory = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($otherRequest): ?\DateTimeImmutable {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            self::assertNull($intent->usedAt());
            self::assertSame(1, $otherRequest->transactional(static fn (Connection $session): int|string => $session->executeStatement(
                "UPDATE med_upload_intent SET used_at = '2026-03-02 09:59:00+00' WHERE id = ? AND used_at IS NULL",
                [self::ACME_INTENT],
            )));
            try {
                $this->uploadIntents()->recordUse($intent);
                self::fail('An upload intent another request used was used again.');
            } catch (UploadIntentUnusable $refusal) {
                self::assertSame('The upload intent is used already.', $refusal->getMessage());
            }

            return $intent->usedAt();
        });
        $otherRequest->close();

        self::assertNull($usedAtInMemory);
        self::assertEquals(new \DateTimeImmutable('2026-03-02 09:59:00+00:00'), $this->storedUsedAt(self::ACME_INTENT));
    }

    #[Test]
    public function usingAnIntentTheTenantHasNotStoredIsRefusedAndStoresNothing(): void
    {
        $this->addIntent(self::OTHER_ORGANIZATION, self::OTHER_INTENT, self::OMAR, '2026-03-02 10:15:00+00:00');
        $otherOrganizationsIntent = $this->intentOf(self::OTHER_ORGANIZATION, self::OTHER_INTENT, self::OMAR, '2026-03-02 10:15:00+00:00');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($otherOrganizationsIntent): void {
            try {
                $this->uploadIntents()->recordUse($otherOrganizationsIntent);
                self::fail('An upload intent of another organization was used.');
            } catch (\LogicException $refusal) {
                self::assertSame('The upload intent is not stored under the tenant.', $refusal->getMessage());
            }
        });

        self::assertNull($otherOrganizationsIntent->usedAt());
        $storedUsedAt = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): mixed => $this->connection()->fetchOne('SELECT used_at FROM med_upload_intent WHERE id = ?', [self::OTHER_INTENT]));
        self::assertNull($storedUsedAt);
    }

    /**
     * The use is one conditional write, so a second request at the same time waits for the first one's row and then
     * finds the intent used. lock_timeout stands in for the wait, which one PHP process cannot sit through.
     */
    #[Test]
    public function aUseAtTheSameTimeWaitsForTheFirstAndThenChangesNothing(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');
        $otherRequest = $this->otherSession();
        $useInOtherRequest = static fn (): int|string => $otherRequest->transactional(static function (Connection $session): int|string {
            $session->executeStatement("SET LOCAL lock_timeout = '200ms'");

            return $session->executeStatement(
                "UPDATE med_upload_intent SET used_at = '2026-03-02 10:00:30+00' WHERE id = ? AND used_at IS NULL AND expires_at > '2026-03-02 10:00:30+00'",
                [self::ACME_INTENT],
            );
        });

        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($useInOtherRequest): void {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            $this->uploadIntents()->recordUse($intent);
            try {
                $useInOtherRequest();
                self::fail('A second use did not wait for the first one.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        });
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        $seenByOtherRequest = $otherRequest->transactional(static fn (Connection $session): mixed => $session->fetchOne('SELECT count(*) FROM med_upload_intent WHERE id = ?', [self::ACME_INTENT]));
        $changedAfterTheFirstUse = $useInOtherRequest();
        self::getContainer()->get(TenantContext::class)->clear();
        $otherRequest->close();

        self::assertSame(1, $seenByOtherRequest, 'The other request does not see the intent, so 0 below proves nothing.');
        self::assertSame(0, $changedAfterTheFirstUse);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->storedUsedAt(self::ACME_INTENT));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expiryTimesAtOrBeforeNow(): iterable
    {
        yield 'expired at this second' => ['2026-03-02 10:00:00+00:00'];
        yield 'expired a minute ago' => ['2026-03-02 09:59:00+00:00'];
    }

    #[Test]
    #[DataProvider('expiryTimesAtOrBeforeNow')]
    public function anExpiredIntentIsRefusedAndStaysUnused(string $expiresAt): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, $expiresAt);

        $usedAtInMemory = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\DateTimeImmutable {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            try {
                $this->uploadIntents()->recordUse($intent);
                self::fail('An expired upload intent was used.');
            } catch (UploadIntentUnusable $refusal) {
                self::assertSame('The upload intent has expired.', $refusal->getMessage());
            }

            return $intent->usedAt();
        });

        self::assertNull($usedAtInMemory);
        self::assertNull($this->storedUsedAt(self::ACME_INTENT));
    }

    #[Test]
    public function anIntentExpiringASecondFromNowCanStillBeUsed(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:00:01+00:00');

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $intent = $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
            self::assertNotNull($intent);
            $this->uploadIntents()->recordUse($intent);
        });

        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->storedUsedAt(self::ACME_INTENT));
    }

    #[Test]
    public function addingAnIntentOutsideATransactionIsRefusedAndStoresNothing(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->uploadIntents()->add($this->intentOf(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00'));
            self::fail('An upload intent was added outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Adding an upload intent needs an open transaction.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM med_upload_intent')));
    }

    #[Test]
    public function readingAnIntentOutsideATransactionIsRefused(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Reading an upload intent needs an open transaction.');
        $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT));
    }

    #[Test]
    public function usingAnIntentOutsideATransactionIsRefusedAndChangesNothing(): void
    {
        $this->addIntent(self::ACME_FOODS, self::ACME_INTENT, self::MIA, '2026-03-02 10:15:00+00:00');
        $intent = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?UploadIntent => $this->uploadIntents()->byId(new UploadIntentId(self::ACME_INTENT)));
        self::assertNotNull($intent);
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->uploadIntents()->recordUse($intent);
            self::fail('An upload intent was used outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Using an upload intent needs an open transaction.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }

        self::assertNull($intent->usedAt());
        self::assertNull($this->storedUsedAt(self::ACME_INTENT));
    }

    private function addIntent(string $organizationId, string $id, string $userId, string $expiresAt): void
    {
        $this->transactions()->committedAs($organizationId, fn () => $this->uploadIntents()->add($this->intentOf($organizationId, $id, $userId, $expiresAt)));
    }

    private function intentOf(string $organizationId, string $id, string $userId, string $expiresAt): UploadIntent
    {
        return UploadIntent::open(new UploadIntentId($id), new OrganizationId($organizationId), new UserId($userId), UploadPurpose::Screenshot, 10_485_760, ['image/png', 'image/webp'], (self::ACME_FOODS === $organizationId ? 'acme/' : 'other/').$id, new \DateTimeImmutable($expiresAt));
    }

    private function storedUsedAt(string $id): ?\DateTimeImmutable
    {
        $usedAt = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT used_at FROM med_upload_intent WHERE id = ?', [$id]));
        self::assertNotFalse($usedAt, 'The upload intent has no row.');
        if (null === $usedAt) {
            return null;
        }
        self::assertIsString($usedAt);

        return new \DateTimeImmutable($usedAt);
    }

    /**
     * A second connection to the database, as a second request would have; each of its transactions takes the tenant
     * the shared TenantContext holds when it begins.
     */
    private function otherSession(): CloseOnFailedBeginConnection
    {
        return new CloseOnFailedBeginConnection(
            $this->connection()->getParams(),
            new TenantMiddleware(self::getContainer()->get(TenantContext::class))->wrap(new PgSQLDriver()),
        );
    }

    private function uploadIntents(): DoctrineUploadIntents
    {
        return new DoctrineUploadIntents($this->entityManager(), $this->clock);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function mediaRows(): MediaRows
    {
        return new MediaRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
