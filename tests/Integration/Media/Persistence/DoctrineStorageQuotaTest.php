<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\Media\Domain\MediaObject;
use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadPurpose;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\Media\Infrastructure\Doctrine\DoctrineStorageQuota;
use App\Media\Infrastructure\Doctrine\DoctrineUploadIntents;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineStorageQuota on the real med_object and med_upload_intent tables as pinround_app, under row-level security.
 * The time asked about is 2026-03-02 10:00 UTC.
 */
final class DoctrineStorageQuotaTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1801';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000e1802';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1803';
    private const string AT = '2026-03-02 10:00:00+00:00';
    private const string LATER = '2026-03-02 10:15:00+00:00';
    private const string SAME_PICTURE = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    protected function setUp(): void
    {
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->mediaRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    /**
     * Each row the total must leave out is larger than the ones it counts, so counting any of them shows.
     */
    #[Test]
    public function bytesInUseAtSumsThePendingAndReadyObjectsAndTheOpenIntentsOfTheOrganization(): void
    {
        $this->addMediaObject(self::ACME_FOODS, 3_000);
        $this->addMediaObject(self::ACME_FOODS, 4_000, ready: true);
        $this->addMediaObject(self::ACME_FOODS, 50_000, rejected: true);
        $this->addIntent(self::ACME_FOODS, 1_000, self::LATER);
        $this->addIntent(self::ACME_FOODS, 200, '2026-03-02 10:00:01+00:00');
        $this->addIntent(self::ACME_FOODS, 60_000, self::AT);
        $this->addIntent(self::ACME_FOODS, 70_000, '2026-03-02 09:00:00+00:00');
        $this->addUsedIntent(self::ACME_FOODS, 90_000);
        $this->addMediaObject(self::OTHER_ORGANIZATION, 80_000, ready: true);
        $this->addIntent(self::OTHER_ORGANIZATION, 100_000, self::LATER);

        $bytesInUseOfAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT)));
        $bytesInUseOfOther = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::OTHER_ORGANIZATION), new \DateTimeImmutable(self::AT)));

        self::assertSame(3_000 + 4_000 + 1_000 + 200, $bytesInUseOfAcme);
        self::assertSame(180_000, $bytesInUseOfOther);
    }

    #[Test]
    public function bytesInUseAtOfAnotherOrganizationThanTheTenantIsZero(): void
    {
        $this->addMediaObject(self::OTHER_ORGANIZATION, 80_000, ready: true);
        $this->addIntent(self::OTHER_ORGANIZATION, 100_000, self::LATER);

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::OTHER_ORGANIZATION), new \DateTimeImmutable(self::AT))));
    }

    #[Test]
    public function bytesInUseAtIsZeroWithNothingThatTakesSpace(): void
    {
        $this->addMediaObject(self::ACME_FOODS, 50_000, rejected: true);
        $this->addUsedIntent(self::ACME_FOODS, 90_000);

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT))));
    }

    /**
     * A total past 4 GiB, which a 32-bit integer would wrap, comes back whole.
     */
    #[Test]
    public function bytesInUseAtKeepsLargeTotalsWhole(): void
    {
        $this->addMediaObject(self::ACME_FOODS, 53_687_091_200);
        $this->addIntent(self::ACME_FOODS, 3, self::LATER);

        self::assertSame(53_687_091_203, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT))));
    }

    /**
     * Completing turns an open intent into a used one and a pending media object of the same size in one commit, so the
     * total is the same before and after: counted once, as the intent or as the object.
     */
    #[Test]
    public function anUploadCompletedCountsTheSameAsItsOpenIntentNotTwiceOrNotAtAll(): void
    {
        $intentId = $this->addIntent(self::ACME_FOODS, 2_048, self::LATER);
        $before = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT)));

        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($intentId): void {
            $intent = $this->uploadIntents()->byId($intentId);
            self::assertNotNull($intent);
            $this->uploadIntents()->recordUse($intent, new \DateTimeImmutable(self::AT));
            $this->mediaObjects()->add(MediaObject::upload($this->mediaObjects()->nextIdentity(), new OrganizationId(self::ACME_FOODS), $intent->storageKey(), 'image/png', $intent->maxBytes(), new UserId(self::MIA), new \DateTimeImmutable(self::AT)));
        });
        $after = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT)));

        self::assertSame([2_048, 2_048], [$before, $after]);
    }

    /**
     * Under READ COMMITTED each statement reads its own snapshot: two would miss an upload whose complete commits
     * between them, counting neither its intent nor its media object.
     */
    #[Test]
    public function bytesInUseAtReadsBothTablesInOneStatement(): void
    {
        $sentSql = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $debugData = $this->debugData();
            $debugData->reset();
            $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT));

            return self::sqlOf($debugData);
        });

        self::assertCount(1, $sentSql, implode("\n", $sentSql));
        self::assertStringContainsString('FROM med_object', $sentSql[0]);
        self::assertStringContainsString('FROM med_upload_intent', $sentSql[0]);
    }

    /**
     * With sequential scans off, as on tables too large to read whole, both sums read only an index, not the rows.
     */
    #[Test]
    public function bytesInUseAtIsServedByIndexesAlone(): void
    {
        $this->addRowsTheQuotaSkips(self::ACME_FOODS, 1_000);
        $plan = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): string {
            $this->connection()->executeStatement('SET LOCAL enable_seqscan = off');
            $debugData = $this->debugData();
            $debugData->reset();
            $this->storageQuota()->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT));
            $sentSql = self::sqlOf($debugData);
            self::assertCount(1, $sentSql);
            $explained = $this->connection()->fetchFirstColumn('EXPLAIN '.$sentSql[0], [self::ACME_FOODS, 'pending', 'ready', self::ACME_FOODS, self::AT]);

            return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $explained));
        });

        self::assertStringContainsString('Index Only Scan using med_object_status_bytes_idx', $plan);
        self::assertStringContainsString('Index Only Scan using med_upload_intent_open_idx', $plan);
    }

    /**
     * lock_timeout stands in for the wait of a second begin, which one PHP process cannot sit through.
     */
    #[Test]
    public function lockMakesTheOrganizationsNextLockWaitUntilTheTransactionEndsAndNoOtherOrganizations(): void
    {
        $otherRequest = $this->otherSession();
        $lockInOtherRequest = static fn (string $organizationId): mixed => $otherRequest->transactional(static function (Connection $session) use ($organizationId): mixed {
            $session->executeStatement("SET LOCAL lock_timeout = '200ms'");

            return $session->fetchOne('SELECT 1 FROM (SELECT pg_advisory_xact_lock(hashtextextended(?, 0))) AS taken', [DoctrineStorageQuota::LOCK_PREFIX.$organizationId]);
        });

        $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($lockInOtherRequest): void {
            $this->storageQuota()->lock(new OrganizationId(self::ACME_FOODS));
            try {
                $lockInOtherRequest(self::ACME_FOODS);
                self::fail('A second quota lock of the organization did not wait for the first.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
            self::assertSame(1, $lockInOtherRequest(self::OTHER_ORGANIZATION), 'Another organization waited for Acme Foods\' quota.');
        });
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        $lockedAfterTheEnd = $lockInOtherRequest(self::ACME_FOODS);
        self::getContainer()->get(TenantContext::class)->clear();
        $otherRequest->close();

        self::assertSame(1, $lockedAfterTheEnd);
    }

    /**
     * @return iterable<string, array{\Closure(DoctrineStorageQuota): mixed, string}>
     */
    public static function callsOutsideATransaction(): iterable
    {
        yield 'lock' => [static function (DoctrineStorageQuota $storageQuota): mixed {
            $storageQuota->lock(new OrganizationId(self::ACME_FOODS));

            return null;
        }, 'Locking the storage quota needs an open transaction.'];
        yield 'bytesInUseAt' => [static fn (DoctrineStorageQuota $storageQuota): mixed => $storageQuota->bytesInUseAt(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::AT)), 'Reading the storage in use needs an open transaction.'];
    }

    /**
     * @param \Closure(DoctrineStorageQuota): mixed $call
     */
    #[Test]
    #[DataProvider('callsOutsideATransaction')]
    public function aCallOutsideATransactionIsRefused(\Closure $call, string $refusal): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $call($this->storageQuota());
            self::fail('A storage quota call ran outside a transaction.');
        } catch (\LogicException $refused) {
            self::assertSame($refusal, $refused->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }
    }

    private function addMediaObject(string $organizationId, int $bytes, bool $ready = false, bool $rejected = false): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $bytes, $ready, $rejected): void {
            $mediaId = $this->mediaObjects()->nextIdentity();
            $mediaObject = MediaObject::upload($mediaId, new OrganizationId($organizationId), 'uploads/'.$mediaId->toString(), 'image/png', $bytes, new UserId(self::MIA), new \DateTimeImmutable(self::AT));
            if ($ready) {
                $mediaObject->markReady(self::SAME_PICTURE, 1280, 800, ['view' => $mediaId->toString().'/view.webp']);
            }
            if ($rejected) {
                $mediaObject->reject('Not a picture.');
            }
            $this->mediaObjects()->add($mediaObject);
        });
    }

    private function addIntent(string $organizationId, int $maxBytes, string $expiresAt): UploadIntentId
    {
        $intentId = $this->uploadIntents()->nextIdentity();
        $this->transactions()->committedAs($organizationId, fn () => $this->uploadIntents()->add(UploadIntent::open($intentId, new OrganizationId($organizationId), new UserId(self::MIA), UploadPurpose::Screenshot, 'ticket', 'SHOP-12', $maxBytes, ['image/png'], 'uploads/'.$intentId->toString(), new \DateTimeImmutable($expiresAt))));

        return $intentId;
    }

    private function addUsedIntent(string $organizationId, int $maxBytes): void
    {
        $intentId = $this->addIntent($organizationId, $maxBytes, self::LATER);
        $this->transactions()->committedAs($organizationId, function () use ($intentId): void {
            $intent = $this->uploadIntents()->byId($intentId);
            self::assertNotNull($intent);
            $this->uploadIntents()->recordUse($intent, new \DateTimeImmutable('2026-03-02 09:59:00+00:00'));
        });
    }

    /**
     * Rejected objects and used intents the sums must skip, then fresh statistics and visibility map. On a near-empty
     * table any index on organization_id costs the same, so the planner's pick follows whatever rows earlier tests left;
     * with rows only the status and open indexes can skip, the index-only scans are the cheaper plan.
     */
    private function addRowsTheQuotaSkips(string $organizationId, int $rowsPerTable): void
    {
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $migrator->transactional(static function (Connection $migrator) use ($organizationId, $rowsPerTable): void {
                $migrator->executeStatement(
                    "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason)
                     SELECT CAST(:organization AS uuid), gen_random_uuid(), 'uploads/skipped-' || n, 'image/png', n, CAST(:uploader AS uuid), CAST(:at AS timestamptz), 'rejected', 'Not a picture.'
                     FROM generate_series(1, CAST(:rows AS int)) AS n",
                    ['organization' => $organizationId, 'uploader' => self::MIA, 'at' => self::AT, 'rows' => $rowsPerTable],
                );
                $migrator->executeStatement(
                    "INSERT INTO med_upload_intent (organization_id, id, user_id, purpose, max_bytes, mime_allowed, storage_key, expires_at, used_at, target_kind, target_id)
                     SELECT CAST(:organization AS uuid), gen_random_uuid(), CAST(:user AS uuid), 'screenshot', n, ARRAY['image/png'], 'uploads/used-' || n, CAST(:later AS timestamptz), CAST(:at AS timestamptz), 'ticket', 'SHOP-12'
                     FROM generate_series(1, CAST(:rows AS int)) AS n",
                    ['organization' => $organizationId, 'user' => self::MIA, 'later' => self::LATER, 'at' => self::AT, 'rows' => $rowsPerTable],
                );
            });
        } finally {
            $tenantContext->clear();
        }
        $migrator->executeStatement('VACUUM ANALYZE med_object, med_upload_intent');
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

    private function storageQuota(): DoctrineStorageQuota
    {
        return new DoctrineStorageQuota($this->entityManager());
    }

    private function uploadIntents(): DoctrineUploadIntents
    {
        return new DoctrineUploadIntents($this->entityManager());
    }

    private function mediaObjects(): DoctrineMediaObjects
    {
        return new DoctrineMediaObjects($this->entityManager());
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
