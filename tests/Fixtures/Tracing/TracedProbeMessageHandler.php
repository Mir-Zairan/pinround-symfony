<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Tracing;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class TracedProbeMessageHandler
{
    public const string FAILURE_MESSAGE = 'The traced probe failed with a private detail.';

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(TracedProbeMessage $message): void
    {
        $this->connection->executeQuery('SELECT 1');

        if ($message->isFailing) {
            throw new UnrecoverableMessageHandlingException(self::FAILURE_MESSAGE);
        }
    }
}
