<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaStatus;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\CloseOnFailedBeginConnection;
use App\SharedKernel\Infrastructure\Doctrine\TenantMiddleware;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineMediaObjects on the real med_object table as pinround_app, under row-level security.
 */
final class DoctrineMediaObjectsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1501';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000e1502';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1503';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e1504';
    private const string OTHER_READY_OLDEST = '0197a3c0-0000-7000-8000-0000000e1510';
    private const string ACME_READY_LATER = '0197a3c0-0000-7000-8000-0000000e1511';
    private const string ACME_PENDING = '0197a3c0-0000-7000-8000-0000000e1512';
    private const string ACME_READY_TIED_FIRST = '0197a3c0-0000-7000-8000-0000000e1513';
    private const string ACME_READY_TIED_SECOND = '0197a3c0-0000-7000-8000-0000000e1514';
    private const string ACME_READY_OTHER_PICTURE = '0197a3c0-0000-7000-8000-0000000e1515';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-0000000e1519';
    private const string SAME_PICTURE = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const string OTHER_PICTURE = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

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

    #[Test]
    public function anAddedObjectIsReadBackPending(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?MediaObject => $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING)));

        self::assertNotNull($found);
        self::assertSame(self::ACME_FOODS, $found->organizationId()->toString());
        self::assertSame('acme/'.self::ACME_PENDING, $found->storageKey());
        self::assertSame('image/png', $found->mime());
        self::assertSame(524_288, $found->bytes());
        self::assertSame(self::MIA, $found->uploadedBy()->toString());
        self::assertEquals(new \DateTimeImmutable('2026-03-02 10:00:00+00:00'), $found->createdAt());
        self::assertSame(MediaStatus::Pending, $found->status());
        self::assertNull($found->sha256());
        self::assertNull($found->variants());
    }

    #[Test]
    public function saveStoresTheChangesOfALoadedObject(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $mediaObject = $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING));
            self::assertNotNull($mediaObject);
            $mediaObject->markReady(self::SAME_PICTURE, 1280, 800, ['thumb' => 'acme/thumb.webp', 'full' => 'acme/full.webp']);
            $this->mediaObjects()->save($mediaObject);
        });
        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?MediaObject => $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING)));

        self::assertNotNull($found);
        self::assertSame(MediaStatus::Ready, $found->status());
        self::assertSame(self::SAME_PICTURE, $found->sha256());
        self::assertSame([1280, 800], [$found->width(), $found->height()]);
        self::assertSame(['full' => 'acme/full.webp', 'thumb' => 'acme/thumb.webp'], $found->variants());
    }

    #[Test]
    public function savingAReadyObjectAgainLeavesTheStoredRowAsTheFirstSaveLeftIt(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);

        [$afterFirstSave, $afterSecondSave] = $this->transactions()->committedAs(self::ACME_FOODS, function (): array {
            $mediaObject = $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING));
            self::assertNotNull($mediaObject);
            $mediaObject->markReady(self::SAME_PICTURE, 1280, 800, ['thumb' => 'acme/thumb.webp', 'full' => 'acme/full.webp']);
            $this->mediaObjects()->save($mediaObject);
            $afterFirstSave = $this->storedRow(self::ACME_PENDING);
            $this->mediaObjects()->save($mediaObject);

            return [$afterFirstSave, $this->storedRow(self::ACME_PENDING)];
        });
        $afterSaveInALaterTransaction = $this->transactions()->committedAs(self::ACME_FOODS, function (): string {
            $mediaObject = $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING));
            self::assertNotNull($mediaObject);
            $this->mediaObjects()->save($mediaObject);

            return $this->storedRow(self::ACME_PENDING);
        });

        self::assertSame('ready '.self::SAME_PICTURE.' 1280 800 {"full": "acme/full.webp", "thumb": "acme/thumb.webp"}', $afterFirstSave);
        self::assertSame($afterFirstSave, $afterSecondSave);
        self::assertSame($afterFirstSave, $afterSaveInALaterTransaction);
    }

    #[Test]
    public function addingASecondObjectWithAStoredIdIsRefusedAndKeepsTheFirstRow(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);
        $secondWithTheSameId = MediaObject::upload(new MediaId(self::ACME_PENDING), new OrganizationId(self::ACME_FOODS), 'acme/second', 'image/webp', 1024, new UserId(self::MIA), new \DateTimeImmutable('2026-03-02 10:05:00+00:00'));

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->mediaObjects()->add($secondWithTheSameId));
            self::fail('A second media object with a stored id was added.');
        } catch (UniqueConstraintViolationException $refusal) {
            self::assertStringContainsString('med_object_pkey', $refusal->getMessage());
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame(
            ['acme/'.self::ACME_PENDING.' image/png 524288'],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
                "SELECT storage_key || ' ' || mime || ' ' || bytes FROM med_object WHERE id = ?",
                [self::ACME_PENDING],
            )),
        );
    }

    /**
     * lockById() detached the copy read first, so saving that copy would INSERT its row again.
     */
    #[Test]
    public function savingACopyReadBeforeLockByIdIsRefusedAndKeepsTheLockedRow(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $readFirst = $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING));
            self::assertNotNull($readFirst);
            self::assertNotNull($this->mediaObjects()->lockById(new MediaId(self::ACME_PENDING)));
            $readFirst->reject('Not a picture.');
            try {
                $this->mediaObjects()->save($readFirst);
                self::fail('A copy read before lockById() was saved.');
            } catch (\LogicException $refusal) {
                self::assertSame('Only a media object read through this port, and not replaced by lockById() since, can be saved.', $refusal->getMessage());
            }
        });

        self::assertSame(
            ['pending'],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT status FROM med_object WHERE id = ?', [self::ACME_PENDING])),
        );
    }

    #[Test]
    public function theObjectOfAnotherOrganizationOrAnUnknownIdIsNotFound(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);
        $this->addMedia(self::OTHER_ORGANIZATION, self::OTHER_READY_OLDEST, '2026-03-02 08:00:00+00:00', self::SAME_PICTURE);

        $found = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING))?->storageKey(),
            $this->mediaObjects()->byId(new MediaId(self::OTHER_READY_OLDEST)),
            $this->mediaObjects()->byId(new MediaId(self::UNKNOWN)),
        ]);

        self::assertSame(['acme/'.self::ACME_PENDING, null, null], $found);
    }

    #[Test]
    public function byIdsFindsTheTenantsObjectsOfThoseIdsOnlyAndAnEmptyListNone(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_LATER, '2026-03-02 10:09:00+00:00', self::SAME_PICTURE);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_OTHER_PICTURE, '2026-03-02 07:00:00+00:00', self::OTHER_PICTURE);
        $this->addMedia(self::OTHER_ORGANIZATION, self::OTHER_READY_OLDEST, '2026-03-02 08:00:00+00:00', self::SAME_PICTURE);

        [$found, $none] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            array_map(
                static fn (MediaObject $mediaObject): string => $mediaObject->id()->toString(),
                $this->mediaObjects()->byIds([new MediaId(self::ACME_READY_LATER), new MediaId(self::OTHER_READY_OLDEST), new MediaId(self::UNKNOWN), new MediaId(self::ACME_PENDING)]),
            ),
            $this->mediaObjects()->byIds([]),
        ]);

        self::assertEqualsCanonicalizing([self::ACME_READY_LATER, self::ACME_PENDING], $found);
        self::assertSame([], $none);
    }

    #[Test]
    public function byIdsLeavesTheObjectsItReadsOutOfChangeTracking(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_LATER, '2026-03-02 10:09:00+00:00', self::SAME_PICTURE);

        $readOnly = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->entityManager()->clear();
            $found = $this->mediaObjects()->byIds([new MediaId(self::ACME_PENDING), new MediaId(self::ACME_READY_LATER)]);

            return array_map(fn (MediaObject $mediaObject): bool => $this->entityManager()->getUnitOfWork()->isReadOnly($mediaObject), $found);
        });

        self::assertSame([true, true], $readOnly);
    }

    /**
     * The other organization's copy of the picture is the oldest of all and has the lowest id; Acme Foods' tied pair
     * is added in the reverse order of their ids, and its later one has a lower id than both.
     */
    #[Test]
    public function readyBySha256GivesTheTenantsOldestReadyObjectWithThatHashAndTheLowerIdOfATie(): void
    {
        $this->addMedia(self::OTHER_ORGANIZATION, self::OTHER_READY_OLDEST, '2026-03-02 08:00:00+00:00', self::SAME_PICTURE);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_OTHER_PICTURE, '2026-03-02 07:00:00+00:00', self::OTHER_PICTURE);
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 09:00:00+00:00', null);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_LATER, '2026-03-02 10:09:00+00:00', self::SAME_PICTURE);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_TIED_SECOND, '2026-03-02 10:08:00+00:00', self::SAME_PICTURE);
        $this->addMedia(self::ACME_FOODS, self::ACME_READY_TIED_FIRST, '2026-03-02 10:08:00+00:00', self::SAME_PICTURE);

        $foundByAcme = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->mediaObjects()->readyBySha256(self::SAME_PICTURE)?->id()->toString(),
            $this->mediaObjects()->readyBySha256(self::OTHER_PICTURE)?->id()->toString(),
            $this->mediaObjects()->readyBySha256(str_repeat('0', 64)),
        ]);
        $foundByOther = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => [
            $this->mediaObjects()->readyBySha256(self::SAME_PICTURE)?->id()->toString(),
            $this->mediaObjects()->readyBySha256(self::OTHER_PICTURE),
        ]);

        self::assertSame([self::ACME_READY_TIED_FIRST, self::ACME_READY_OTHER_PICTURE, null], $foundByAcme);
        self::assertSame([self::OTHER_READY_OLDEST, null], $foundByOther);
    }

    #[Test]
    public function lockByIdHoldsTheRowUntilTheTransactionEnds(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);
        $this->addMedia(self::OTHER_ORGANIZATION, self::OTHER_READY_OLDEST, '2026-03-02 08:00:00+00:00', self::SAME_PICTURE);
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $connection = $this->entityManager()->getConnection();
        $otherSession = new CloseOnFailedBeginConnection(
            $connection->getParams(),
            new TenantMiddleware($tenantContext)->wrap(new PgSQLDriver()),
        );
        $lockRowNowait = static fn (): array => $otherSession->transactional(static fn (Connection $session): array => $session->fetchFirstColumn(
            'SELECT id FROM med_object WHERE id = ? FOR UPDATE NOWAIT',
            [self::ACME_PENDING],
        ));

        $connection->beginTransaction();
        try {
            self::assertSame('acme/'.self::ACME_PENDING, $this->mediaObjects()->lockById(new MediaId(self::ACME_PENDING))?->storageKey());
            self::assertNull($this->mediaObjects()->lockById(new MediaId(self::OTHER_READY_OLDEST)), 'The object of another organization is not found.');
            try {
                $lockRowNowait();
                self::fail('The media object row was not locked.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $connection->rollBack();
        }

        self::assertSame([self::ACME_PENDING], $lockRowNowait());
        $otherSession->close();
        $this->entityManager()->clear();
        $tenantContext->clear();
    }

    #[Test]
    public function lockByIdReadsTheRowFreshAndDropsUnsavedChangesOfALoadedCopy(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);

        [$locked, $loaded] = $this->transactions()->committedAs(self::ACME_FOODS, function (): array {
            $loaded = $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING));
            self::assertNotNull($loaded);
            $loaded->reject('Not a picture.');
            $locked = $this->mediaObjects()->lockById(new MediaId(self::ACME_PENDING));
            $this->entityManager()->flush();

            return [$locked, $loaded];
        });
        $stored = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT status FROM med_object WHERE id = ?', [self::ACME_PENDING]));

        self::assertNotNull($locked);
        self::assertNotSame($loaded, $locked);
        self::assertSame(MediaStatus::Pending, $locked->status());
        self::assertSame('pending', $stored);
    }

    #[Test]
    public function addingAnObjectOutsideATransactionIsRefusedAndStoresNothing(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->mediaObjects()->add($this->mediaOf(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00'));
            self::fail('A media object was added outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Adding a media object needs an open transaction.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM med_object')));
    }

    #[Test]
    public function savingAnObjectOutsideATransactionIsRefusedAndChangesNothing(): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', null);
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        $mediaObject = $this->entityManager()->wrapInTransaction(fn (): ?MediaObject => $this->mediaObjects()->byId(new MediaId(self::ACME_PENDING)));
        self::assertNotNull($mediaObject);
        $mediaObject->reject('Not a picture.');
        try {
            $this->mediaObjects()->save($mediaObject);
            self::fail('A media object was saved outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Saving a media object needs an open transaction.', $refusal->getMessage());
        } finally {
            $this->entityManager()->clear();
            self::getContainer()->get(TenantContext::class)->clear();
        }

        self::assertSame('pending', $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT status FROM med_object WHERE id = ?', [self::ACME_PENDING])));
    }

    /**
     * @return iterable<string, array{\Closure(DoctrineMediaObjects): mixed, string}>
     */
    public static function readsOutsideATransaction(): iterable
    {
        yield 'byId' => [static fn (DoctrineMediaObjects $mediaObjects): mixed => $mediaObjects->byId(new MediaId(self::ACME_PENDING)), 'Reading a media object needs an open transaction.'];
        yield 'lockById' => [static fn (DoctrineMediaObjects $mediaObjects): mixed => $mediaObjects->lockById(new MediaId(self::ACME_PENDING)), 'Locking a media object needs an open transaction.'];
        yield 'byIds' => [static fn (DoctrineMediaObjects $mediaObjects): mixed => $mediaObjects->byIds([new MediaId(self::ACME_PENDING)]), 'Reading media objects needs an open transaction.'];
        yield 'readyBySha256' => [static fn (DoctrineMediaObjects $mediaObjects): mixed => $mediaObjects->readyBySha256(self::SAME_PICTURE), 'Reading a media object needs an open transaction.'];
    }

    /**
     * @param \Closure(DoctrineMediaObjects): mixed $read
     */
    #[Test]
    #[DataProvider('readsOutsideATransaction')]
    public function readingOutsideATransactionIsRefused(\Closure $read, string $refusal): void
    {
        $this->addMedia(self::ACME_FOODS, self::ACME_PENDING, '2026-03-02 10:00:00+00:00', self::SAME_PICTURE);
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains($refusal);
        $read($this->mediaObjects());
    }

    /**
     * Adds a pending object, or a ready one when $sha256 is given.
     */
    private function addMedia(string $organizationId, string $id, string $createdAt, ?string $sha256): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $id, $createdAt, $sha256): void {
            $mediaObject = $this->mediaOf($organizationId, $id, $createdAt);
            if (null !== $sha256) {
                $mediaObject->markReady($sha256, 1280, 800, ['thumb' => $id.'/thumb.webp', 'full' => $id.'/full.webp']);
            }
            $this->mediaObjects()->add($mediaObject);
        });
    }

    /**
     * The columns markReady() and reject() change, as one line, read inside the caller's transaction.
     */
    private function storedRow(string $id): string
    {
        $row = $this->connection()->fetchOne(
            "SELECT concat_ws(' ', status, sha256, width, height, variants::text, reject_reason) FROM med_object WHERE id = ?",
            [$id],
        );
        self::assertIsString($row, 'The media object has no row.');

        return $row;
    }

    private function mediaOf(string $organizationId, string $id, string $createdAt): MediaObject
    {
        $uploadedBy = self::ACME_FOODS === $organizationId ? self::MIA : self::OMAR;

        return MediaObject::upload(new MediaId($id), new OrganizationId($organizationId), (self::ACME_FOODS === $organizationId ? 'acme/' : 'other/').$id, 'image/png', 524_288, new UserId($uploadedBy), new \DateTimeImmutable($createdAt));
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
