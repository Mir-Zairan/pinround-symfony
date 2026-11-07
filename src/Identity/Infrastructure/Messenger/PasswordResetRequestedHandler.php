<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Messenger;

use App\Identity\Application\PasswordResetRequested;
use App\Identity\Application\SendPasswordResetLink;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs in worker-notify, inside the bus's transaction (doctrine_transaction), with no organization set: the message
 * carries no TenantStamp.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PasswordResetRequestedHandler
{
    public function __construct(private SendPasswordResetLink $sendPasswordResetLink, private ClockInterface $clock)
    {
    }

    public function __invoke(PasswordResetRequested $request): void
    {
        ($this->sendPasswordResetLink)($request, $this->clock->now());
    }
}
