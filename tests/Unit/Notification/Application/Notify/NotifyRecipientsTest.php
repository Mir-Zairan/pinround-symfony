<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAnswered;
use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\Event\ReplyPosted;
use App\Conversation\Api\QuestionQueries;
use App\Notification\Api\BellView;
use App\Notification\Api\Event\PingSent;
use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Notify\DeliveryTimes;
use App\Notification\Application\Notify\NewNotification;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Application\Notify\Notifications;
use App\Notification\Application\Notify\NotifyRecipients;
use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Domain\Ping;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\PingId;
use App\Organization\Api\MemberNameQueries;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Notification\Application\Ping\InMemoryPings;
use App\Tests\Unit\Notification\Application\Realtime\BellRefreshesDouble;
use App\Ticketing\Api\Event\TicketAssigned;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotifyRecipientsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000006a01';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000006a02';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000006a03';
    private const string MESSAGE = '0197a3c0-0000-7000-8000-000000006a05';
    private const string OTHER_MESSAGE = '0197a3c0-0000-7000-8000-000000006a06';
    private const string REPLY = '0197a3c0-0000-7000-8000-000000006a07';
    private const string SECOND_REPLY = '0197a3c0-0000-7000-8000-000000006a08';
    private const string THIRD_REPLY = '0197a3c0-0000-7000-8000-000000006a0c';
    private const string PING = '0197a3c0-0000-7000-8000-000000006a09';
    private const string HELD_PING = '0197a3c0-0000-7000-8000-000000006a0a';
    private const string UNKNOWN_PING = '0197a3c0-0000-7000-8000-000000006a0b';
    private const string LENA = '0197a3c0-0000-7000-8000-000000006a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000006a12';
    private const string MIA = '0197a3c0-0000-7000-8000-000000006a13';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000006a14';
    private const string FORMER_MEMBER = '0197a3c0-0000-7000-8000-000000006a15';
    private const string OCCURRED_AT = '2027-03-15 10:00:00 UTC';

    private OnceOnlyHandlingDouble $onceOnlyHandling;
    private NotificationsDouble $notifications;
    private BellRefreshesDouble $bellRefreshes;
    private InMemoryPings $pings;
    private bool $isQuietHoursOn = false;
    /** @var list<string> user id strings with TICKET muted */
    private array $mutedBy = [];
    private bool $isTicketGone = false;
    private QuestionQueriesDouble $questionQueries;

    protected function setUp(): void
    {
        $this->onceOnlyHandling = new OnceOnlyHandlingDouble();
        $this->onceOnlyHandling->messageId = 'message-1';
        $this->notifications = new NotificationsDouble();
        $this->questionQueries = new QuestionQueriesDouble($this->notifications, [self::MESSAGE, self::OTHER_MESSAGE]);
        $this->bellRefreshes = new BellRefreshesDouble();
        $this->pings = new InMemoryPings();
        $this->pings->actAsTenant(new OrganizationId(self::ACME_FOODS));
        $this->pings->add(self::pingToOmar(self::PING, null));
    }

    /**
     * @return iterable<string, array{object, NotificationKind, string, array<string, string>}>
     */
    public static function events(): iterable
    {
        $at = new \DateTimeImmutable(self::OCCURRED_AT);

        yield 'ping' => [new PingSent(self::ACME_FOODS, self::TICKET, self::PING, self::LENA, self::OMAR, self::LENA, $at), NotificationKind::Ping, self::OMAR, ['pingId' => self::PING]];
        yield 'question' => [new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::OMAR, self::LENA, $at), NotificationKind::Question, self::OMAR, ['messageId' => self::MESSAGE]];
        yield 'answer' => [new QuestionAnswered(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::OMAR, self::LENA, $at), NotificationKind::Answer, self::OMAR, ['messageId' => self::MESSAGE]];
        yield 'reply' => [new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::REPLY, self::OMAR, self::LENA, $at), NotificationKind::Reply, self::OMAR, ['messageId' => self::MESSAGE, 'replyId' => self::REPLY]];
        yield 'assigned' => [new TicketAssigned(self::ACME_FOODS, self::TICKET, 'SHOP-42', null, self::OMAR, self::LENA, $at), NotificationKind::Assigned, self::OMAR, []];
    }

    /**
     * @param array<string, string> $sourceIds
     */
    #[Test]
    #[DataProvider('events')]
    public function eachEventNotifiesItsRecipientOnceWithTheActorAndTicketSnapshot(object $event, NotificationKind $kind, string $recipientId, array $sourceIds): void
    {
        $this->notify($event);

        self::assertCount(1, $this->notifications->rows);
        $row = $this->notifications->rows[0];
        $at = new \DateTimeImmutable(self::OCCURRED_AT);
        self::assertSame(
            [self::ACME_FOODS, $recipientId, $kind, self::LENA, self::TICKET, ['actorName' => 'Lena', 'ticketKey' => 'SHOP-42', ...$sourceIds]],
            [$row->organizationId->toString(), $row->recipientId->toString(), $row->kind, $row->actorId->toString(), $row->ticketId->toString(), $row->data],
        );
        self::assertEquals($at, $row->createdAt);
        self::assertNull($row->deliverAfter);
        self::assertSame([NotifyRecipients::HANDLER_NAME], $this->onceOnlyHandling->handlerNames);
        $toast = NotificationKind::Answer === $kind ? 'Lena answered your question.' : null;
        self::assertEquals([[new RefreshBell(self::ACME_FOODS, $recipientId, $toast), $row->deliverAfter]], $this->bellRefreshes->sent);
    }

    #[Test]
    public function onlyAnAnswerRefreshesTheBellWithAToastNamingWhoAnswered(): void
    {
        $at = new \DateTimeImmutable(self::OCCURRED_AT);
        $this->notify(new QuestionAnswered(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::OMAR, self::MIA, $at));
        $this->onceOnlyHandling->messageId = 'message-2';
        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::OTHER_MESSAGE, self::OMAR, self::MIA, $at));

        self::assertSame(['Mia answered your question.', null], array_map(static fn (array $sent): ?string => $sent[0]->toast, $this->bellRefreshes->sent));
    }

    #[Test]
    public function aHeldPingKeepsTheDeliveryTimeStoredOnItSoItsRefreshWaitsForTheRelease(): void
    {
        $deliveredAt = new \DateTimeImmutable('2026-03-04 09:00:00 Europe/Berlin');
        $this->pings->add(self::pingToOmar(self::HELD_PING, $deliveredAt));

        $this->notify(new PingSent(self::ACME_FOODS, self::TICKET, self::HELD_PING, self::LENA, self::OMAR, self::LENA, $deliveredAt));

        self::assertCount(1, $this->notifications->rows);
        self::assertEquals($deliveredAt, $this->notifications->rows[0]->deliverAfter);
        self::assertEquals([[new RefreshBell(self::ACME_FOODS, self::OMAR, null), $deliveredAt]], $this->bellRefreshes->sent);
    }

    #[Test]
    public function aPingIsTimedBySendPingNotAgainWhenItsEventIsHandled(): void
    {
        $this->isQuietHoursOn = true;

        // 23:00 in Berlin, but the ping was stored as not held, so the row is not held either.
        $this->notify(new PingSent(self::ACME_FOODS, self::TICKET, self::PING, self::LENA, self::OMAR, self::LENA, new \DateTimeImmutable('2026-03-03 22:00:00 UTC')));

        self::assertCount(1, $this->notifications->rows);
        self::assertNull($this->notifications->rows[0]->deliverAfter);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pingsThatNotifyNobody(): iterable
    {
        yield 'cleared before its event is handled' => [self::HELD_PING];
        yield 'unknown' => [self::UNKNOWN_PING];
    }

    #[Test]
    #[DataProvider('pingsThatNotifyNobody')]
    public function aPingThatIsNoLongerOpenNotifiesNobody(string $pingId): void
    {
        $clearedPing = self::pingToOmar(self::HELD_PING, null);
        $clearedPing->clear(PingClearedReason::Acted, new \DateTimeImmutable(self::OCCURRED_AT));
        $this->pings->add($clearedPing);

        $this->notify(new PingSent(self::ACME_FOODS, self::TICKET, $pingId, self::LENA, self::OMAR, self::LENA, new \DateTimeImmutable(self::OCCURRED_AT)));

        self::assertSame([[], [], [NotifyRecipients::HANDLER_NAME]], [$this->notifications->rows, $this->bellRefreshes->sent, $this->onceOnlyHandling->handlerNames]);
    }

    #[Test]
    public function aQuestionAnsweredOrWithdrawnBeforeItsEventIsHandledNotifiesNobodyWhileAnOpenOneStillDoes(): void
    {
        $this->questionQueries = new QuestionQueriesDouble($this->notifications, [self::OTHER_MESSAGE]);

        $this->notify($this->questionTo(self::OMAR));
        $this->onceOnlyHandling->messageId = 'message-2';
        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::OTHER_MESSAGE, self::MIA, self::LENA, new \DateTimeImmutable(self::OCCURRED_AT)));

        self::assertSame([[NotificationKind::Question, self::OTHER_MESSAGE]], array_map(static fn (NewNotification $row): array => [$row->kind, $row->data['messageId']], $this->notifications->rows));
        self::assertSame([self::MIA], array_map(static fn (array $sent): string => $sent[0]->userId, $this->bellRefreshes->sent));
    }

    #[Test]
    public function aQuestionIsCheckedWhileTheLockAboutItIsHeld(): void
    {
        $this->notify($this->questionTo(self::OMAR));

        self::assertSame([[self::MESSAGE, true]], $this->questionQueries->checks);
        self::assertCount(1, $this->notifications->rows);
    }

    /**
     * Only the question itself must be open: its answer, a reply about it and a ping are not checked against it.
     *
     * @param array<string, string> $sourceIds
     */
    #[Test]
    #[DataProvider('events')]
    public function onlyAQuestionWaitsForItsMessageToBeOpen(object $event, NotificationKind $kind, string $recipientId, array $sourceIds): void
    {
        $this->questionQueries = new QuestionQueriesDouble($this->notifications, []);

        $this->notify($event);

        self::assertSame(NotificationKind::Question === $kind ? [] : [$kind], array_map(static fn (NewNotification $row): NotificationKind => $row->kind, $this->notifications->rows));
        self::assertSame(NotificationKind::Question === $kind ? [self::MESSAGE] : [], $this->notifications->lockedMessageIds);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function questionsInQuietHours(): iterable
    {
        // 3 March 2026 in Berlin is UTC+1; Mia has no known zone, so she is timed on UTC.
        yield '23:00 in Berlin is held until 09:00 Berlin' => [self::OMAR, '2026-03-03 22:00:00 UTC', '2026-03-04T09:00:00+01:00'];
        yield '09:00 in Berlin is delivered at once' => [self::OMAR, '2026-03-03 08:00:00 UTC', null];
        yield '08:30 UTC is held until 09:00 UTC for Mia' => [self::MIA, '2026-03-03 08:30:00 UTC', '2026-03-03T09:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('questionsInQuietHours')]
    public function withQuietHoursOnAQuestionIsHeldUntil09OnTheRecipientsClock(string $recipientId, string $askedAt, ?string $expectedDeliverAfter): void
    {
        $this->isQuietHoursOn = true;

        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, $recipientId, self::LENA, new \DateTimeImmutable($askedAt)));

        self::assertCount(1, $this->notifications->rows);
        $row = $this->notifications->rows[0];
        self::assertSame($expectedDeliverAfter, $row->deliverAfter?->format(\DATE_ATOM));
        // A held row is created at its delivery time, so a digest sent during the hold cannot skip it.
        self::assertEquals($row->deliverAfter ?? new \DateTimeImmutable($askedAt), $row->createdAt);
        self::assertEquals([[new RefreshBell(self::ACME_FOODS, $recipientId, null), $row->deliverAfter]], $this->bellRefreshes->sent);
    }

    #[Test]
    public function withQuietHoursOffAQuestionAtNightIsDeliveredAtOnce(): void
    {
        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::OMAR, self::LENA, new \DateTimeImmutable('2026-03-03 22:00:00 UTC')));

        self::assertCount(1, $this->notifications->rows);
        self::assertNull($this->notifications->rows[0]->deliverAfter);
    }

    #[Test]
    public function aReplyFoldedIntoTheLastUnreadOneStillRefreshesTheBell(): void
    {
        $first = new \DateTimeImmutable(self::OCCURRED_AT);
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::REPLY, self::OMAR, self::LENA, $first));
        $this->onceOnlyHandling->messageId = 'message-2';

        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::SECOND_REPLY, self::OMAR, self::MIA, $first->modify('+10 seconds')));

        self::assertCount(1, $this->notifications->rows);
        self::assertSame([self::OMAR, self::OMAR], array_map(static fn (array $sent): string => $sent[0]->userId, $this->bellRefreshes->sent));
    }

    #[Test]
    public function nobodyIsNotifiedOfTheirOwnAction(): void
    {
        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::LENA, self::LENA, new \DateTimeImmutable(self::OCCURRED_AT)));

        self::assertSame([], $this->notifications->rows);
        self::assertSame([], $this->bellRefreshes->sent);
    }

    #[Test]
    public function aRecipientWhoMutedTheTicketGetsNoNotification(): void
    {
        $this->mutedBy = [self::OMAR];

        $this->notify($this->questionTo(self::OMAR));
        $this->onceOnlyHandling->messageId = 'message-2';
        $this->notify($this->questionTo(self::MIA));

        self::assertSame([self::MIA], $this->recipientsOfRows());
        self::assertSame([self::MIA], array_map(static fn (array $sent): string => $sent[0]->userId, $this->bellRefreshes->sent));
    }

    #[Test]
    public function aRecipientWhoLostAccessToTheTeamOnlyTicketGetsNoNotification(): void
    {
        $this->notify($this->questionTo(self::RAJ));
        $this->onceOnlyHandling->messageId = 'message-2';
        $this->notify($this->questionTo(self::MIA));

        self::assertSame([self::MIA], $this->recipientsOfRows());
        self::assertSame([self::MIA], array_map(static fn (array $sent): string => $sent[0]->userId, $this->bellRefreshes->sent));
    }

    #[Test]
    public function aTicketThatIsGoneNotifiesNobody(): void
    {
        $this->isTicketGone = true;

        $this->notify($this->questionTo(self::OMAR));

        self::assertSame([], $this->notifications->rows);
    }

    #[Test]
    public function aRecipientWhoIsNoLongerAMemberGetsNoNotification(): void
    {
        $this->notify($this->questionTo(self::FORMER_MEMBER));

        self::assertSame([], $this->notifications->rows);
    }

    #[Test]
    public function anActorWhoIsNoLongerAMemberNotifiesNobody(): void
    {
        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::OMAR, self::FORMER_MEMBER, new \DateTimeImmutable(self::OCCURRED_AT)));

        self::assertSame([], $this->notifications->rows);
    }

    #[Test]
    public function aRedeliveredEventStillWritesOneNotification(): void
    {
        $event = $this->questionTo(self::OMAR);

        $this->notify($event);
        $this->notify($event);

        self::assertSame([NotifyRecipients::HANDLER_NAME, NotifyRecipients::HANDLER_NAME], $this->onceOnlyHandling->handlerNames);
        self::assertSame([self::OMAR], $this->recipientsOfRows());
        self::assertCount(1, $this->bellRefreshes->sent);
    }

    #[Test]
    public function aReplyWithinTheFoldWindowOfTheLastUnreadReplyUpdatesItInsteadOfAddingOne(): void
    {
        $first = new \DateTimeImmutable(self::OCCURRED_AT);
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::REPLY, self::OMAR, self::LENA, $first));
        $this->onceOnlyHandling->messageId = 'message-2';

        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::SECOND_REPLY, self::OMAR, self::MIA, $first->modify('+120 seconds')));

        self::assertCount(1, $this->notifications->rows);
        $row = $this->notifications->rows[0];
        self::assertSame([self::MIA, ['actorName' => 'Mia', 'ticketKey' => 'SHOP-42', 'messageId' => self::MESSAGE, 'replyId' => self::SECOND_REPLY]], [$row->actorId->toString(), $row->data]);
        self::assertEquals($first->modify('+120 seconds'), $row->createdAt);
    }

    #[Test]
    public function aReplyPastTheFoldWindowAddsASecondNotification(): void
    {
        $first = new \DateTimeImmutable(self::OCCURRED_AT);
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::REPLY, self::OMAR, self::LENA, $first));
        $this->onceOnlyHandling->messageId = 'message-2';

        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::SECOND_REPLY, self::OMAR, self::MIA, $first->modify('+121 seconds')));

        self::assertSame([self::LENA, self::MIA], array_map(static fn (NewNotification $row): string => $row->actorId->toString(), $this->notifications->rows));
    }

    #[Test]
    public function aReplyFoldsOnlyIntoAnUnreadReplyAboutTheSameMessage(): void
    {
        $first = new \DateTimeImmutable(self::OCCURRED_AT);
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::OTHER_MESSAGE, self::REPLY, self::OMAR, self::LENA, $first));
        $this->onceOnlyHandling->messageId = 'message-2';
        $this->notify(new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::OMAR, self::LENA, $first));
        $this->onceOnlyHandling->messageId = 'message-3';

        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::SECOND_REPLY, self::OMAR, self::MIA, $first->modify('+10 seconds')));

        self::assertSame(
            [[NotificationKind::Reply, self::OTHER_MESSAGE], [NotificationKind::Question, self::MESSAGE], [NotificationKind::Reply, self::MESSAGE]],
            array_map(static fn (NewNotification $row): array => [$row->kind, $row->data['messageId']], $this->notifications->rows),
        );
    }

    #[Test]
    public function heldRepliesOfOneNightFoldIntoOneRowCreatedAtTheirDeliveryButNotIntoTheShownOneBefore(): void
    {
        $this->isQuietHoursOn = true;
        // 3 March 2026 in Berlin is UTC+1: 17:59 there is shown at once, 18:00 and 23:00 are held until 09:00.
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::REPLY, self::OMAR, self::LENA, new \DateTimeImmutable('2026-03-03 16:59:00 UTC')));
        $this->onceOnlyHandling->messageId = 'message-2';
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::SECOND_REPLY, self::OMAR, self::MIA, new \DateTimeImmutable('2026-03-03 17:00:00 UTC')));
        $this->onceOnlyHandling->messageId = 'message-3';

        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::THIRD_REPLY, self::OMAR, self::LENA, new \DateTimeImmutable('2026-03-03 22:00:00 UTC')));

        self::assertSame(
            [[self::LENA, '2026-03-03T16:59:00+00:00', null], [self::LENA, '2026-03-04T09:00:00+01:00', '2026-03-04T09:00:00+01:00']],
            array_map(static fn (NewNotification $row): array => [$row->actorId->toString(), $row->createdAt->format(\DATE_ATOM), $row->deliverAfter?->format(\DATE_ATOM)], $this->notifications->rows),
        );
    }

    #[Test]
    public function aShownReplyFoldsIntoTheHeldReplyOnceItsHoldHasEnded(): void
    {
        $this->isQuietHoursOn = true;
        // 4 March 2026 in Berlin is UTC+1: 23:00 the night before is held until 09:00, 09:01 is shown at once.
        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::REPLY, self::OMAR, self::LENA, new \DateTimeImmutable('2026-03-03 22:00:00 UTC')));
        $this->onceOnlyHandling->messageId = 'message-2';

        $this->notify(new ReplyPosted(self::ACME_FOODS, self::TICKET, self::MESSAGE, self::SECOND_REPLY, self::OMAR, self::MIA, new \DateTimeImmutable('2026-03-04 08:01:00 UTC')));

        self::assertSame(
            [[self::MIA, '2026-03-04T08:01:00+00:00', '2026-03-04T09:00:00+01:00']],
            array_map(static fn (NewNotification $row): array => [$row->actorId->toString(), $row->createdAt->format(\DATE_ATOM), $row->deliverAfter?->format(\DATE_ATOM)], $this->notifications->rows),
        );
    }

    private function questionTo(string $recipientId): QuestionAsked
    {
        return new QuestionAsked(self::ACME_FOODS, self::TICKET, self::MESSAGE, $recipientId, self::LENA, new \DateTimeImmutable(self::OCCURRED_AT));
    }

    /**
     * @return list<string>
     */
    private function recipientsOfRows(): array
    {
        return array_map(static fn (NewNotification $row): string => $row->recipientId->toString(), $this->notifications->rows);
    }

    private function notify(object $event): void
    {
        \assert($event instanceof PingSent || $event instanceof QuestionAsked || $event instanceof QuestionAnswered || $event instanceof ReplyPosted || $event instanceof TicketAssigned);
        $handler = new NotifyRecipients(
            $this->onceOnlyHandling,
            $this->notificationQueries(),
            $this->ticketTurnQueries(),
            self::ticketVisibilities(),
            self::ticketKeyQueries(),
            self::memberNameQueries(),
            $this->notifications,
            $this->bellRefreshes,
            $this->pings,
            new DeliveryTimes(self::pingSettingsQueries($this->isQuietHoursOn), new InMemoryMemberTimeZones(self::ACME_FOODS, [self::OMAR => 'Europe/Berlin'])),
            $this->questionQueries,
        );

        $handler($event);
    }

    private static function pingToOmar(string $pingId, ?\DateTimeImmutable $deliverAfter): Ping
    {
        return Ping::send(new PingId($pingId), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Any news?', $deliverAfter, new \DateTimeImmutable('2026-03-03 21:00:00 UTC'));
    }

    private static function pingSettingsQueries(bool $isQuietHoursOn): PingSettingsQueries
    {
        return new readonly class($isQuietHoursOn) implements PingSettingsQueries {
            public function __construct(private bool $isQuietHoursOn)
            {
            }

            public function pingLimitHoursOf(OrganizationId $organizationId): int
            {
                throw new \LogicException('NotifyRecipients does not read the ping limit.');
            }

            public function isAutoClearOn(OrganizationId $organizationId): bool
            {
                throw new \LogicException('NotifyRecipients does not read the auto-clear switch.');
            }

            public function isQuietHoursOn(OrganizationId $organizationId): bool
            {
                return $this->isQuietHoursOn;
            }
        };
    }

    private function notificationQueries(): NotificationQueries
    {
        return new readonly class(new TicketId(self::TICKET), $this->mutedBy) implements NotificationQueries {
            /**
             * @param list<string> $mutedBy
             */
            public function __construct(private TicketId $mutedTicketId, private array $mutedBy)
            {
            }

            public function isMuted(UserId $userId, TicketId $ticketId): bool
            {
                return $ticketId->equals($this->mutedTicketId) && \in_array($userId->toString(), $this->mutedBy, true);
            }

            public function bell(UserId $userId): BellView
            {
                throw new \LogicException('NotifyRecipients does not read the bell.');
            }
        };
    }

    private function ticketTurnQueries(): TicketTurnQueries
    {
        $snapshot = $this->isTicketGone ? null : new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            'project-shop',
            'step-build',
            null,
            'build',
            new TeamId(self::SHOP_TEAM),
            new UserId(self::LENA),
            'Lena',
            [],
            [],
            'team',
            'normal',
            null,
            new UserId(self::MIA),
        );

        return new readonly class($snapshot) implements TicketTurnQueries {
            public function __construct(private ?TicketTurnSnapshot $snapshot)
            {
            }

            public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
            {
                return true === $this->snapshot?->ticketId->equals($ticketId) ? $this->snapshot : null;
            }
        };
    }

    /**
     * Lena leads and Omar is a member of the ticket's team; Mia reported it; Raj and the former member are in no team.
     */
    private static function ticketVisibilities(): TicketVisibilities
    {
        return new readonly class(new TeamId(self::SHOP_TEAM), [self::LENA, self::OMAR]) implements TicketVisibilities {
            /**
             * @param list<string> $shopTeamMemberIds
             */
            public function __construct(private TeamId $shopTeamId, private array $shopTeamMemberIds)
            {
            }

            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                $teamIds = \in_array($viewerId->toString(), $this->shopTeamMemberIds, true) ? [$this->shopTeamId] : [];

                return TicketVisibility::for($organizationId, $viewerId, $teamIds, false, null);
            }
        };
    }

    private static function ticketKeyQueries(): TicketKeyQueries
    {
        return new readonly class(new TicketId(self::TICKET)) implements TicketKeyQueries {
            public function __construct(private TicketId $shopTicketId)
            {
            }

            public function keyOf(TicketId $ticketId): ?string
            {
                return $ticketId->equals($this->shopTicketId) ? 'SHOP-42' : null;
            }
        };
    }

    /**
     * Like IdentityMemberNameQueries: only members of the organization, keyed by id in id order, capped.
     */
    private static function memberNameQueries(): MemberNameQueries
    {
        return new readonly class(new OrganizationId(self::ACME_FOODS), [self::LENA => 'Lena', self::OMAR => 'Omar', self::MIA => 'Mia', self::RAJ => 'Raj']) implements MemberNameQueries {
            /**
             * @param array<string, string> $memberNames
             */
            public function __construct(private OrganizationId $memberOrganizationId, private array $memberNames)
            {
            }

            public function displayNamesOf(OrganizationId $organizationId, array $userIds): array
            {
                if (\count($userIds) > self::MAX_USER_IDS) {
                    throw new \InvalidArgumentException('Too many user ids.');
                }
                $members = $organizationId->equals($this->memberOrganizationId) ? $this->memberNames : [];
                $names = [];
                foreach ($userIds as $userId) {
                    if (isset($members[$userId->toString()])) {
                        $names[$userId->toString()] = $members[$userId->toString()];
                    }
                }
                ksort($names);

                return $names;
            }
        };
    }
}

/**
 * Like DbalNotifications: a reply folds into an unread, not done reply about the same message created at or after
 * $since and held until the same time, or, for a reply that is not held, whose hold ended by the reply's created_at;
 * the rows have no read or done state here, so every row is unread. A lock about a message is only recorded.
 */
final class NotificationsDouble implements Notifications
{
    /** @var list<NewNotification> */
    public array $rows = [];

    /** @var list<string> the message id of each lock about a message, in the order taken */
    public array $lockedMessageIds = [];

    public function add(NewNotification $notification): void
    {
        $this->rows[] = $notification;
    }

    public function foldIntoUnreadReply(NewNotification $reply, \DateTimeImmutable $since): bool
    {
        $messageId = $reply->data['messageId'] ?? null;
        if (NotificationKind::Reply !== $reply->kind || null === $messageId) {
            throw new \LogicException('Only a reply notification about a message folds into another.');
        }
        $isFolded = false;
        foreach ($this->rows as $index => $row) {
            if (NotificationKind::Reply === $row->kind
                && $row->organizationId->equals($reply->organizationId)
                && $row->recipientId->equals($reply->recipientId)
                && ($row->data['messageId'] ?? null) === $messageId
                && $row->createdAt >= $since
                && ($row->deliverAfter?->format('U.u') === $reply->deliverAfter?->format('U.u')
                    || (null === $reply->deliverAfter && null !== $row->deliverAfter && $row->deliverAfter <= $reply->createdAt))
            ) {
                $this->rows[$index] = new NewNotification($row->organizationId, $row->recipientId, $row->kind, $reply->actorId, $row->ticketId, $reply->data, $reply->createdAt, $row->deliverAfter);
                $isFolded = true;
            }
        }

        return $isFolded;
    }

    public function markPingsDone(OrganizationId $organizationId, UserId $recipientId, TicketId $ticketId, \DateTimeImmutable $at): int
    {
        throw new \LogicException('NotifyRecipients does not mark pings done.');
    }

    public function releaseDue(OrganizationId $organizationId, \DateTimeImmutable $at): array
    {
        throw new \LogicException('NotifyRecipients does not release held notifications.');
    }

    public function lockAboutMessage(OrganizationId $organizationId, string $messageId): void
    {
        $this->lockedMessageIds[] = $messageId;
    }

    public function markDoneAboutMessage(OrganizationId $organizationId, TicketId $ticketId, string $messageId, array $kinds, \DateTimeImmutable $at): array
    {
        throw new \LogicException('NotifyRecipients does not mark notifications done.');
    }
}

/**
 * Like DbalQuestionQueries: a question is open while its id is among $openQuestionIds. It records, for each check,
 * whether the notifications double held the lock about that question when it was made.
 */
final class QuestionQueriesDouble implements QuestionQueries
{
    /** @var list<array{string, bool}> each checked question id and whether its lock was held then */
    public array $checks = [];

    /**
     * @param list<string> $openQuestionIds
     */
    public function __construct(private readonly NotificationsDouble $notifications, private readonly array $openQuestionIds)
    {
    }

    public function isOpen(string $questionId): bool
    {
        $this->checks[] = [$questionId, \in_array($questionId, $this->notifications->lockedMessageIds, true)];

        return \in_array($questionId, $this->openQuestionIds, true);
    }
}
