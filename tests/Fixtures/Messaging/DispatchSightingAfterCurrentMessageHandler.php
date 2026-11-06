<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[AsMessageHandler]
final readonly class DispatchSightingAfterCurrentMessageHandler
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(DispatchSightingAfterCurrentMessage $message): void
    {
        $this->bus->dispatch(new RecordHandlerSighting(), [new DispatchAfterCurrentBusStamp()]);
    }
}
