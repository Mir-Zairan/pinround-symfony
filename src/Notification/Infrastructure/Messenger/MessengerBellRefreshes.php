<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Messenger;

use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Dispatches on command.bus, which routes RefreshBell to async_notify (messenger.yaml). The stamp holds it in the one
 * dispatch_after_current_bus middleware every bus shares until the event.bus message being handled has committed;
 * dispatched outside any bus, it goes at once.
 */
final readonly class MessengerBellRefreshes implements BellRefreshes
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function sendOnceCommitted(RefreshBell $refresh, ?\DateTimeImmutable $deliverAfter): void
    {
        if (null !== $deliverAfter && $deliverAfter > $this->clock->now()) {
            return;
        }

        $this->commandBus->dispatch($refresh, [new DispatchAfterCurrentBusStamp()]);
    }
}
