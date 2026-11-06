<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\NoMessageBeingHandled;
use App\SharedKernel\Application\OnceOnlyHandling;
use Doctrine\DBAL\Connection;

/**
 * OnceOnlyHandling on the processed_message table: one row per (message id, handler name) that ran its effect.
 */
final readonly class ProcessedMessageLedger implements OnceOnlyHandling
{
    public function __construct(
        private Connection $connection,
        private MessageBeingHandled $messageBeingHandled,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when $handlerName is empty
     * @throws NoMessageBeingHandled
     */
    public function runOnce(string $handlerName, \Closure $effect): bool
    {
        if ('' === $handlerName) {
            throw new \InvalidArgumentException('A handler name must not be empty.');
        }

        $messageId = $this->messageBeingHandled->messageId() ?? throw new NoMessageBeingHandled();

        // Inside the handler's transaction this is a savepoint, so a failing effect also takes the row back. A second
        // delivery running at the same time waits on the primary key until the first commits, then inserts nothing.
        return $this->connection->transactional(static function (Connection $connection) use ($messageId, $handlerName, $effect): bool {
            $insertedRowCount = $connection->executeStatement(
                'INSERT INTO processed_message (message_id, handler) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$messageId, $handlerName],
            );
            if (0 === $insertedRowCount) {
                return false;
            }

            $effect();

            return true;
        });
    }
}
