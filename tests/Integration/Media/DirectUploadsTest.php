<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadSlot;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Media\Application\DirectUploads;
use App\Media\Application\MediaTargetAuthorizers;
use App\Media\Application\ProcessUpload;
use App\Media\Application\UploadProcessingQueue;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
use App\Media\Infrastructure\Doctrine\DoctrineStorageQuota;
use App\Media\Infrastructure\Doctrine\DoctrineUploadIntents;
use App\Media\Infrastructure\Messenger\MessengerUploadProcessingQueue;
use App\Media\Infrastructure\Storage\ObjectStorageSettings;
use App\Media\Infrastructure\Storage\PresignedUploads;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\DoctrineTransactions;
use App\Tests\Integration\Media\Persistence\MediaRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Media\Application\RecordingMediaTargetAuthorizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * DirectUploads on the real med_upload_intent and med_object tables as pinround_app, under row-level security, and on
 * the real command.bus, which writes ProcessUpload to the outbox. The clock stands at 2026-03-02 10:00 UTC. Mia may
 * attach to the ticket SHOP-12 of Acme Foods.
 */
final class DirectUploadsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1601';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1603';
    private const string NOW = '2026-03-02 10:00:00+00:00';
    private const string TICKET = 'ticket';
    private const string SHOP_12 = 'SHOP-12';
    private const int FIFTY_GIB = 53_687_091_200;

    private MockClock $clock;
    private RecordingMediaTargetAuthorizer $ticketAuthorizer;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->ticketAuthorizer = new RecordingMediaTargetAuthorizer(self::TICKET);
        $this->ticketAuthorizer->allow(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), self::SHOP_12);
        $this->mediaRows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->mediaRows()->removeAllOf(self::ACME_FOODS);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        parent::tearDown();
    }

    #[Test]
    public function theContainerGivesTheUploadsApiAsDirectUploads(): void
    {
        self::assertInstanceOf(DirectUploads::class, self::getContainer()->get('test.media_uploads'));
    }

    #[Test]
    public function aBeginThatFillsTheQuotaExactlyIsStored(): void
    {
        $this->seedStorageUseOf3048Bytes();

        $slot = $this->transactions()->committedAs(self::ACME_FOODS, fn (): UploadSlot => $this->uploads(organizationQuotaBytes: 3_048 + 500)->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), new UploadTarget(self::TICKET, self::SHOP_12), 'screenshot', 'image/png', 500, $this->clock->now()));

        self::assertSame([[$slot->intentId]], $this->rowsOf('SELECT id FROM med_upload_intent WHERE max_bytes = 500'));
    }

    #[Test]
    public function aBeginOneByteOverTheQuotaIsRefusedAsStorageFullAndStoresNothing(): void
    {
        $this->seedStorageUseOf3048Bytes();
        $intentsBefore = $this->rowsOf('SELECT id, max_bytes, used_at FROM med_upload_intent ORDER BY id');
        $objectsBefore = $this->rowsOf('SELECT id, bytes, status FROM med_object ORDER BY id');
        self::assertCount(2, $intentsBefore, 'The seed is one used and one open intent.');
        $this->ticketAuthorizer = new RecordingMediaTargetAuthorizer(self::TICKET);
        $this->ticketAuthorizer->allow(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), self::SHOP_12);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): UploadSlot => $this->uploads(organizationQuotaBytes: 3_048 + 500)->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), new UploadTarget(self::TICKET, self::SHOP_12), 'screenshot', 'image/png', 501, $this->clock->now()));
            self::fail('An upload past the quota was begun.');
        } catch (UploadRefused $refused) {
            self::assertSame(DirectUploads::STORAGE_FULL, $refused->getMessage());
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame($intentsBefore, $this->rowsOf('SELECT id, max_bytes, used_at FROM med_upload_intent ORDER BY id'));
        self::assertSame($objectsBefore, $this->rowsOf('SELECT id, bytes, status FROM med_object ORDER BY id'));
        self::assertSame([], $this->ticketAuthorizer->asked());
    }

    #[Test]
    public function anUploadBegunAndCompletedLeavesAUsedIntentForItsTargetAPendingMediaObjectAndOneProcessUploadInTheOutbox(): void
    {
        $slot = $this->begin();
        $this->clock->modify('+2 minutes');

        $mediaId = $this->complete($slot->intentId);

        self::assertSame(
            [[$slot->intentId, self::TICKET, self::SHOP_12, 'uploads/'.self::ACME_FOODS.'/'.$slot->intentId, 2048, '{image/png}', self::MIA, '2026-03-03 10:00', '2026-03-02 10:02']],
            $this->rowsOf("SELECT id, target_kind, target_id, storage_key, max_bytes, mime_allowed::text, user_id, to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS expires_at, to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS used_at FROM med_upload_intent"),
        );
        self::assertSame(
            [[$mediaId, 'pending', 'image/png', 2048, 'uploads/'.self::ACME_FOODS.'/'.$slot->intentId, self::MIA, '2026-03-02 10:02']],
            $this->rowsOf("SELECT id, status, mime, bytes, storage_key, uploaded_by, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') FROM med_object"),
        );
        self::assertEquals([new ProcessUpload(self::ACME_FOODS, $mediaId)], $this->outboxMessages());
    }

    #[Test]
    public function aSecondCompleteIsRefusedAndAddsNoSecondMediaObjectNorMessage(): void
    {
        $slot = $this->begin();
        $firstMediaId = $this->complete($slot->intentId);
        $this->clock->modify('+1 minute');

        try {
            $this->complete($slot->intentId);
            self::fail('An upload was completed twice.');
        } catch (UploadRefused $refused) {
            self::assertSame('This upload has ended. Upload the file again.', $refused->getMessage());
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame([[$firstMediaId]], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([['2026-03-02 10:00']], $this->rowsOf("SELECT to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') FROM med_upload_intent"));
        self::assertEquals([new ProcessUpload(self::ACME_FOODS, $firstMediaId)], $this->outboxMessages());
    }

    #[Test]
    public function aCompleteThatRollsBackLeavesNeitherTheMediaObjectNorItsOutboxRow(): void
    {
        $slot = $this->begin();
        $failingQueue = new FailingUploadProcessingQueue(new MessengerUploadProcessingQueue(self::getContainer()->get('command.bus'), $this->connection()), $this->connection());

        try {
            $this->complete($slot->intentId, $failingQueue);
            self::fail('The failing queue let the upload complete.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The test fails the transaction after queueing.', $failure->getMessage());
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame([1, 1], $failingQueue->mediaObjectsAndOutboxRowsBeforeTheFailure);
        self::assertSame([], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([[null]], $this->rowsOf('SELECT used_at FROM med_upload_intent'));
        self::assertSame([], $this->outboxMessages());
    }

    #[Test]
    public function aCompleteInsideAnOpenTransactionIsRefusedAndChangesNothing(): void
    {
        $slot = $this->begin();

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($slot): string {
            try {
                $this->uploads()->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId, $this->clock->now());
            } catch (\LogicException $refused) {
                return $refused->getMessage();
            }

            return 'not refused';
        });

        self::assertSame('A transaction of its own must not run inside another transaction.', $refusal);
        self::assertSame([[null]], $this->rowsOf('SELECT used_at FROM med_upload_intent'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([], $this->outboxMessages());
    }

    #[Test]
    public function aCompleteInTransactionWithNoOpenTransactionIsRefusedAndChangesNothing(): void
    {
        $slot = $this->begin();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        try {
            $this->uploads()->completeInTransaction(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId, $this->clock->now());
            self::fail('An upload was completed in a transaction with none open.');
        } catch (\LogicException $refused) {
            self::assertSame('Reading an upload intent needs an open transaction.', $refused->getMessage());
        } finally {
            $this->entityManager()->clear();
            $tenantContext->clear();
        }

        self::assertSame([[null]], $this->rowsOf('SELECT used_at FROM med_upload_intent'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([], $this->outboxMessages());
    }

    #[Test]
    public function aCompleteInTransactionWritesInTheCallersTransactionAndItsRollbackLeavesNothing(): void
    {
        $slot = $this->begin();
        $this->clock->modify('+2 minutes');

        [$completed, $mediaObjectsInside, $outboxRowsInside] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->uploads()->completeInTransaction(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $slot->intentId, $this->clock->now()),
            array_map(array_values(...), $this->connection()->fetchAllAssociative('SELECT id, status FROM med_object')),
            $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'"),
        ]);

        self::assertEquals(new CompletedUpload($slot->intentId, MediaImageStatus::Pending), $completed);
        self::assertSame([[$slot->intentId, 'pending']], $mediaObjectsInside);
        self::assertSame(1, $outboxRowsInside);
        self::assertSame([[null]], $this->rowsOf('SELECT used_at FROM med_upload_intent'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([], $this->outboxMessages());
    }

    #[Test]
    public function aSecondCompleteInTransactionAnswersThePictureOfTheFirstWithItsCurrentStatusAndWritesNothing(): void
    {
        $slot = $this->begin();
        $first = $this->completedInTransaction($slot->intentId);
        $this->rejectAsUnreadable($first->mediaId);
        $this->clock->modify('+1 minute');

        $second = $this->completedInTransaction($slot->intentId);

        self::assertEquals(new CompletedUpload($slot->intentId, MediaImageStatus::Pending), $first);
        self::assertEquals(new CompletedUpload($slot->intentId, MediaImageStatus::Rejected), $second);
        self::assertSame([[$slot->intentId, 'rejected']], $this->rowsOf('SELECT id, status FROM med_object'));
        self::assertSame([['2026-03-02 10:00']], $this->rowsOf("SELECT to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') FROM med_upload_intent"));
        self::assertEquals([new ProcessUpload(self::ACME_FOODS, $slot->intentId)], $this->outboxMessages());
    }

    #[Test]
    public function aCompleteWhoseStoredTargetIsNowDeniedIsRefusedAndChangesNothing(): void
    {
        $slot = $this->begin();
        $this->ticketAuthorizer->deny(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), self::SHOP_12);

        try {
            $this->complete($slot->intentId);
            self::fail('An upload was completed for a target its person may no longer attach to.');
        } catch (UploadTargetDenied $denied) {
            self::assertSame('This person may not add uploads to the target of this upload.', $denied->getMessage());
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame(
            [['organizationId' => self::ACME_FOODS, 'userId' => self::MIA, 'targetId' => self::SHOP_12], ['organizationId' => self::ACME_FOODS, 'userId' => self::MIA, 'targetId' => self::SHOP_12]],
            $this->ticketAuthorizer->asked(),
        );
        self::assertSame([[null]], $this->rowsOf('SELECT used_at FROM med_upload_intent'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([], $this->outboxMessages());
    }

    #[Test]
    public function aCompleteOfAnExpiredIntentSaysThePageWasOpenTooLongAndChangesNothing(): void
    {
        $slot = $this->begin();
        $this->clock->modify('+1 day');

        try {
            $this->complete($slot->intentId);
            self::fail('An expired upload was completed.');
        } catch (UploadRefused $refused) {
            self::assertSame('The page was open too long. Try again.', $refused->getMessage());
        } finally {
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame([[null]], $this->rowsOf('SELECT used_at FROM med_upload_intent'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_object'));
        self::assertSame([], $this->outboxMessages());
    }

    private function begin(): UploadSlot
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): UploadSlot => $this->uploads()->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), new UploadTarget(self::TICKET, self::SHOP_12), 'screenshot', 'image/png', 2048, $this->clock->now()));
    }

    /**
     * As a request would: the tenant is set, no transaction is open, and the entity manager is cleared before the
     * tenant is, as the switch guard refuses while it holds entities.
     */
    private function complete(string $intentId, ?UploadProcessingQueue $uploadProcessingQueue = null): string
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            return $this->uploads($uploadProcessingQueue)->complete(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $intentId, $this->clock->now());
        } finally {
            $this->entityManager()->clear();
            $tenantContext->clear();
        }
    }

    private function completedInTransaction(string $intentId): CompletedUpload
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): CompletedUpload => $this->uploads()->completeInTransaction(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), $intentId, $this->clock->now()));
    }

    /**
     * As the processing worker would. Row-level security applies to the migrator too, so the tenant is set; the CHECK
     * constraint wants a reason with the rejected status.
     */
    private function rejectAsUnreadable(string $mediaId): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $updatedRows = $this->migratorConnection()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement("UPDATE med_object SET status = 'rejected', reject_reason = 'unreadable' WHERE id = ?", [$mediaId]));
        } finally {
            $tenantContext->clear();
        }
        self::assertSame(1, $updatedRows);
    }

    /**
     * @return list<list<mixed>>
     */
    private function rowsOf(string $sql): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => array_map(
            array_values(...),
            $this->entityManager()->getConnection()->fetchAllAssociative($sql),
        ));
    }

    /**
     * @return list<object> the message of each outbox row, oldest first
     */
    private function outboxMessages(): array
    {
        $serializer = new PhpSerializer();
        $messages = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' ORDER BY id") as $body) {
            self::assertIsString($body);
            $messages[] = $serializer->decode(['body' => $body])->getMessage();
        }

        return $messages;
    }

    private function uploads(?UploadProcessingQueue $uploadProcessingQueue = null, int $organizationQuotaBytes = self::FIFTY_GIB): DirectUploads
    {
        $entityManager = $this->entityManager();
        $tenantContext = self::getContainer()->get(TenantContext::class);

        return new DirectUploads(
            new DoctrineUploadIntents($entityManager),
            new DoctrineMediaObjects($entityManager),
            new DoctrineStorageQuota($entityManager),
            new PresignedUploads(new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'eu-west-1', 'acme-key', 'acme-secret'), $this->clock),
            $uploadProcessingQueue ?? new MessengerUploadProcessingQueue(self::getContainer()->get('command.bus'), $this->connection()),
            new MediaTargetAuthorizers([$this->ticketAuthorizer]),
            new DoctrineTransactions($entityManager, $tenantContext, self::getContainer()->get('doctrine')),
            $tenantContext,
            $organizationQuotaBytes,
        );
    }

    /**
     * Acme Foods' 2 048-byte upload completed into a pending object, and a second intent of 1 000 bytes left open.
     */
    private function seedStorageUseOf3048Bytes(): void
    {
        $slot = $this->begin();
        $this->complete($slot->intentId);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): UploadSlot => $this->uploads()->begin(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), new UploadTarget(self::TICKET, self::SHOP_12), 'screenshot', 'image/webp', 1_000, $this->clock->now()));
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

    private function mediaRows(): MediaRows
    {
        return new MediaRows($this->migratorConnection(), self::getContainer()->get(TenantContext::class));
    }

    private function migratorConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }
}
