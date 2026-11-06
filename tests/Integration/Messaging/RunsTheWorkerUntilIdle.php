<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Runs Messenger's Worker, the loop behind messenger:consume, on the real buses, retry strategies and failure
 * transport, until no transport has a message left. Not the command itself: its service reset after each message
 * empties the in-memory transports, and with them a retry the test waits for (a real Valkey queue keeps it).
 */
trait RunsTheWorkerUntilIdle
{
    private static function runTheWorkerUntilIdle(string ...$transportNames): void
    {
        $container = self::getContainer();
        $receiverLocator = $container->get('messenger.receiver_locator');
        $receivers = [];
        foreach ($transportNames as $transportName) {
            $receiver = $receiverLocator->get($transportName);
            self::assertInstanceOf(ReceiverInterface::class, $receiver);
            $receivers[$transportName] = $receiver;
        }

        $eventDispatcher = $container->get('event_dispatcher');
        $worker = new Worker($receivers, $container->get('messenger.routable_message_bus'), $eventDispatcher, $container->get('monolog.logger.messenger'));
        $stopWhenIdle = static function (WorkerRunningEvent $event) use ($worker): void {
            if ($event->getWorker() === $worker && $event->isWorkerIdle()) {
                $worker->stop();
            }
        };
        $eventDispatcher->addListener(WorkerRunningEvent::class, $stopWhenIdle);
        try {
            $worker->run(['sleep' => 1000, 'time_limit' => 30]);
        } finally {
            $eventDispatcher->removeListener(WorkerRunningEvent::class, $stopWhenIdle);
        }
    }
}
