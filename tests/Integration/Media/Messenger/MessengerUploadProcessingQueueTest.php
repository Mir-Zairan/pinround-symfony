<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Messenger;

use App\Media\Application\ProcessUpload;
use App\Media\Infrastructure\Messenger\MessengerUploadProcessingQueue;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MessengerUploadProcessingQueue on the real command.bus, whose doctrine_transaction middleware would commit an outbox
 * row of its own outside a transaction. DirectUploadsTest covers the queueing inside complete()'s transaction.
 */
final class MessengerUploadProcessingQueueTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1701';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000e1709';

    protected function tearDown(): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [self::MEDIA]);
        parent::tearDown();
    }

    #[Test]
    public function queueingOutsideATransactionIsRefusedAndWritesNoOutboxRow(): void
    {
        $queue = new MessengerUploadProcessingQueue(self::getContainer()->get('command.bus'), $this->connection());

        try {
            $queue->queue(new ProcessUpload(self::ACME_FOODS, self::MEDIA));
            self::fail('An upload was queued with no transaction open.');
        } catch (\LogicException $refused) {
            self::assertSame('An upload is queued for processing in the transaction of its media object; none is open.', $refused->getMessage());
        }

        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame([], $this->connection()->fetchFirstColumn("SELECT id FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [self::MEDIA]));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
