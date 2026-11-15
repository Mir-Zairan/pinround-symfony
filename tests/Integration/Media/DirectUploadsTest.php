<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media;

use App\Media\Api\UploadRefused;
use App\Media\Api\UploadSlot;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Media\Application\DirectUploads;
use App\Media\Application\MediaTargetAuthorizers;
use App\Media\Application\ProcessUpload;
use App\Media\Application\UploadProcessingQueue;
use App\Media\Infrastructure\Doctrine\DoctrineMediaObjects;
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

    private function uploads(?UploadProcessingQueue $uploadProcessingQueue = null): DirectUploads
    {
        $entityManager = $this->entityManager();
        $tenantContext = self::getContainer()->get(TenantContext::class);

        return new DirectUploads(
            new DoctrineUploadIntents($entityManager),
            new DoctrineMediaObjects($entityManager),
            new PresignedUploads(new ObjectStorageSettings('http://minio:9000', 'https://files.example.test', 'pinround', 'eu-west-1', 'acme-key', 'acme-secret'), $this->clock),
            $uploadProcessingQueue ?? new MessengerUploadProcessingQueue(self::getContainer()->get('command.bus'), $this->connection()),
            new MediaTargetAuthorizers([$this->ticketAuthorizer]),
            new DoctrineTransactions($entityManager, $tenantContext, self::getContainer()->get('doctrine')),
            $tenantContext,
        );
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
        return new MediaRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
