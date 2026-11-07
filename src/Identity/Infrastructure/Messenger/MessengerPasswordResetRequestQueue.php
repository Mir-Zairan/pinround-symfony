<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Messenger;

use App\Identity\Application\PasswordResetRequested;
use App\Identity\Application\PasswordResetRequestQueue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the request to async_notify (messenger.yaml) straight away, not through the outbox: the request that queues
 * it writes nothing to the database, so there is no transaction for it to wait on.
 */
final readonly class MessengerPasswordResetRequestQueue implements PasswordResetRequestQueue
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function add(PasswordResetRequested $request): void
    {
        $this->commandBus->dispatch($request);
    }
}
