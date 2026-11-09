<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\Organization\Api\Event\MemberInvited;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A MemberInvited handler that fails the next delivery once armed, as a failing audit write would: the transaction
 * the event's handlers share then rolls back, and the retry runs them all again. Its priority runs it after the other
 * handlers registered for MemberInvited itself, so they have run when it fails.
 */
#[AsMessageHandler(bus: 'event.bus', priority: -10)]
final class FailOnceOnMemberInvitedHandler
{
    private bool $isArmed = false;

    public function failNextDelivery(): void
    {
        $this->isArmed = true;
    }

    /**
     * @throws \RuntimeException once after failNextDelivery(); retried like any failure
     */
    public function __invoke(MemberInvited $memberInvited): void
    {
        if ($this->isArmed) {
            $this->isArmed = false;

            throw new \RuntimeException('A MemberInvited handler failed.');
        }
    }
}
