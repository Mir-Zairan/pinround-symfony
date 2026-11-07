<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The worker resets services between messages (ADR-0008), so the next message never runs under the id of the last.
 */
final class MessageBeingHandledResetTest extends KernelTestCase
{
    #[Test]
    public function theKernelResetForgetsTheMessageBeingHandled(): void
    {
        $container = self::getContainer();
        $messageBeingHandled = $container->get(MessageBeingHandled::class);
        $messageBeingHandled->set('0197a3c0-0000-7000-8000-000000000301');

        $container->get('services_resetter')->reset();

        self::assertNull($messageBeingHandled->messageId());
    }
}
