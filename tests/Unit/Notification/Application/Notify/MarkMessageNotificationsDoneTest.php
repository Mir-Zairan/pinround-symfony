<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Api\Event\QuestionAnswered;
use App\Notification\Application\Notify\MarkMessageNotificationsDone;
use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Application\Realtime\RefreshBell;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\AutoClear\InMemoryNotifications;
use App\Tests\Unit\Notification\Application\Realtime\BellRefreshesDouble;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Omar asked Lena a question about SHOP-1 (MESSAGE); Lena replied on it, which notified Omar. Mia's open question
 * about OTHER_MESSAGE, and a question with MESSAGE's id on SHOP-2, must stay open whatever closes MESSAGE.
 */
final class MarkMessageNotificationsDoneTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000006c01';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-000000006c02';
    private const string SHOP_2 = '0197a3c0-0000-7000-8000-000000006c03';
    private const string MESSAGE = '0197a3c0-0000-7000-8000-000000006c04';
    private const string OTHER_MESSAGE = '0197a3c0-0000-7000-8000-000000006c05';
    private const string LENA = '0197a3c0-0000-7000-8000-000000006c11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000006c12';
    private const string MIA = '0197a3c0-0000-7000-8000-000000006c13';
    private const string CLOSED_AT = '2027-03-15 10:05:00 UTC';

    private InMemoryNotifications $notifications;
    private BellRefreshesDouble $bellRefreshes;

    protected function setUp(): void
    {
        $this->notifications = new InMemoryNotifications();
        $this->bellRefreshes = new BellRefreshesDouble();
        $this->notifications->add(self::row(self::LENA, NotificationKind::Question, self::SHOP_1, self::MESSAGE, '2027-03-16 08:00:00 UTC'));
        $this->notifications->add(self::row(self::OMAR, NotificationKind::Reply, self::SHOP_1, self::MESSAGE, null));
        $this->notifications->add(self::row(self::OMAR, NotificationKind::Answer, self::SHOP_1, self::MESSAGE, null));
        $this->notifications->add(self::row(self::LENA, NotificationKind::Question, self::SHOP_1, self::OTHER_MESSAGE, null));
        $this->notifications->add(self::row(self::MIA, NotificationKind::Question, self::SHOP_2, self::MESSAGE, null));
    }

    #[Test]
    public function anAnswerMarksDoneOnlyTheAskedPersonsQuestionRowHeldOrNotAndRefreshesTheirBell(): void
    {
        $this->handle(new QuestionAnswered(self::ACME_FOODS, self::SHOP_1, self::MESSAGE, self::OMAR, self::LENA, new \DateTimeImmutable(self::CLOSED_AT)));

        self::assertEquals([new \DateTimeImmutable(self::CLOSED_AT), null, null, null, null], $this->notifications->doneTimes());
        self::assertEquals([[new RefreshBell(self::ACME_FOODS, self::LENA, null), null]], $this->bellRefreshes->sent);
    }

    #[Test]
    public function aWithdrawalMarksDoneTheQuestionAndReplyRowsAboutItAndRefreshesEachRecipientOnceInUserIdOrder(): void
    {
        $this->handle(new MessageRemoved(self::ACME_FOODS, self::SHOP_1, self::MESSAGE, self::OMAR, new \DateTimeImmutable(self::CLOSED_AT)));

        $closedAt = new \DateTimeImmutable(self::CLOSED_AT);
        self::assertEquals([$closedAt, $closedAt, null, null, null], $this->notifications->doneTimes());
        self::assertEquals([
            [new RefreshBell(self::ACME_FOODS, self::LENA, null), null],
            [new RefreshBell(self::ACME_FOODS, self::OMAR, null), null],
        ], $this->bellRefreshes->sent);
        self::assertSame([[self::ACME_FOODS, self::MESSAGE]], $this->notifications->lockedMessages);
    }

    #[Test]
    public function aSecondDeliveryMarksNothingMoreAndRefreshesNobody(): void
    {
        $removal = new MessageRemoved(self::ACME_FOODS, self::SHOP_1, self::MESSAGE, self::OMAR, new \DateTimeImmutable(self::CLOSED_AT));
        $this->handle($removal);
        $this->bellRefreshes->sent = [];

        $this->handle(new MessageRemoved(self::ACME_FOODS, self::SHOP_1, self::MESSAGE, self::OMAR, new \DateTimeImmutable('2027-03-15 11:00:00 UTC')));

        $closedAt = new \DateTimeImmutable(self::CLOSED_AT);
        self::assertEquals([$closedAt, $closedAt, null, null, null], $this->notifications->doneTimes());
        self::assertSame([], $this->bellRefreshes->sent);
    }

    private function handle(QuestionAnswered|MessageRemoved $event): void
    {
        new MarkMessageNotificationsDone($this->notifications, $this->bellRefreshes)($event);
    }

    private static function row(string $recipientId, NotificationKind $kind, string $ticketId, string $messageId, ?string $deliverAfter): NewNotification
    {
        $createdAt = new \DateTimeImmutable($deliverAfter ?? '2027-03-15 10:00:00 UTC');

        return new NewNotification(
            new OrganizationId(self::ACME_FOODS),
            new UserId($recipientId),
            $kind,
            new UserId(self::MIA === $recipientId ? self::OMAR : self::LENA),
            new TicketId($ticketId),
            ['actorName' => 'Lena', 'ticketKey' => 'SHOP-1', 'messageId' => $messageId],
            $createdAt,
            null === $deliverAfter ? null : $createdAt,
        );
    }
}
