<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Messenger;

use App\Organization\Api\Event\MemberInvited;
use App\Organization\Application\EmailInvitationLink;
use App\Organization\Infrastructure\Messenger\QueueInvitationLinkEmailHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class QueueInvitationLinkEmailHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000e01';
    private const string MIAS_INVITATION = '0197a3c0-0000-7000-8000-000000000e02';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000e03';

    #[Test]
    public function aMemberInvitedQueuesTheEmailOfItsInvitationByIds(): void
    {
        $commandBus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $this->dispatched[] = $envelope;

                return $envelope;
            }
        };

        new QueueInvitationLinkEmailHandler($commandBus)(new MemberInvited(self::ACME_FOODS, self::MIAS_INVITATION, self::SARA, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        self::assertCount(1, $commandBus->dispatched);
        self::assertEquals(new EmailInvitationLink(self::ACME_FOODS, self::MIAS_INVITATION), $commandBus->dispatched[0]->getMessage());
    }
}
