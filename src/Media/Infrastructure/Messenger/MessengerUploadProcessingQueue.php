<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Messenger;

use App\Media\Application\ProcessUpload;
use App\Media\Application\UploadProcessingQueue;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on command.bus, which routes ProcessUpload to the outbox (messenger.yaml); its doctrine_transaction joins
 * the open transaction as a savepoint, so the outbox row commits or rolls back with it, and the relay moves it on to
 * async_media.
 */
final readonly class MessengerUploadProcessingQueue implements UploadProcessingQueue
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private Connection $connection,
    ) {
    }

    public function queue(ProcessUpload $processUpload): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the media object.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An upload is queued for processing in the transaction of its media object; none is open.');
        }

        $this->commandBus->dispatch($processUpload);
    }
}
