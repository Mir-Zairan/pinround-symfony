<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAsked;
use App\Notification\Api\BellGroup;
use App\Notification\Api\Event\PingSent;
use App\Notification\Api\NotificationQueries;
use App\Notification\Api\PingCommands;
use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Digest\DigestItems;
use App\Notification\Application\Notify\NotifyRecipients;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * P04.T6 criterion 2 from the ping to the bell: Mia pings Lena through PingCommands, NotifyRecipients handles the
 * PingSent from the container, and NotificationQueries reads Lena's bell, on the visibility cast's team-only SHOP
 * ticket. Lena lives in Europe/Berlin (UTC+1 in March), where quiet hours run 18:00 to 09:00, so the frozen 21:00 UTC
 * is 22:00 for her and a held row waits until 08:00 UTC. Each case runs in one rolled-back transaction under Acme Foods.
 */
final class QuietHoursDeliveryTest extends VisibilityLeakCast
{
    /** In a month ntf_notification has a partition for (Version20261114130000), before Berlin's March clock change. */
    private const string SENT_AT = '2027-03-03 21:00:00 UTC';
    private const string QUIET_HOURS_END = '2027-03-04 08:00:00+00';
    private const string PING_BODY = 'Any news on this one?';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(new \DateTimeImmutable(self::SENT_AT));
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        $heldSlots = $stateValkey->keys('ping:'.self::ACME_FOODS.':*');
        if (\is_array($heldSlots) && [] !== $heldSlots) {
            $stateValkey->del($heldSlots);
        }
        parent::tearDown();
    }

    #[Test]
    public function aPingSentInLenasQuietHoursReachesHerBellOnlyAt0900HerTime(): void
    {
        [$storedDeliverAfter, $rowDeliverAfter, $bellAt0759, $bellAt0800] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpLena(isQuietHoursOn: true);
            $pingId = $this->pingLenaAsMia();
            $storedDeliverAfter = $this->storedDeliverAfterOf($pingId);
            $this->handlePingSent($pingId, $storedDeliverAfter);

            $this->clock->modify('2027-03-04 07:59:00 UTC');
            $bellAt0759 = $this->lenasBell();
            $this->clock->modify('2027-03-04 08:00:00 UTC');

            return [$storedDeliverAfter, $this->lenasRowsDeliverAfter(), $bellAt0759, $this->lenasBell()];
        });

        self::assertSame(self::QUIET_HOURS_END, $storedDeliverAfter);
        self::assertSame([self::QUIET_HOURS_END], $rowDeliverAfter);
        self::assertSame([0, []], $bellAt0759);
        self::assertSame([1, [['Mia', 'ping', self::PING_BODY]]], $bellAt0800);
    }

    #[Test]
    public function aQuestionAskedAt2300LenasTimeIsHeldUntil0900HerTime(): void
    {
        [$rowDeliverAfter, $bellAt0759, $bellAt0800] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpLena(isQuietHoursOn: true);
            $this->handle($this->questionToLenaAsRaj('2027-03-03 22:00:00 UTC'));

            $this->clock->modify('2027-03-04 07:59:00 UTC');
            $bellAt0759 = $this->lenasBell();
            $this->clock->modify('2027-03-04 08:00:00 UTC');

            return [$this->lenasRowsDeliverAfter(), $bellAt0759, $this->lenasBell()];
        });

        self::assertSame([self::QUIET_HOURS_END], $rowDeliverAfter);
        self::assertSame([0, []], $bellAt0759);
        self::assertSame([1, [['Raj', 'question', null]]], $bellAt0800);
    }

    /**
     * A digest goes out at 23:30 UTC while the 22:00 question is held, listing only the 16:00 ping; the next starts
     * after that send time, so it finds the held question only because the row is created at 08:00, its delivery.
     */
    #[Test]
    public function aQuestionHeldWhileADigestGoesOutIsInTheNextDigest(): void
    {
        [$rowCreatedAt, $digestDuringTheHold, $nextDigest] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpLena(isQuietHoursOn: true);
            $this->clock->modify('2027-03-03 16:00:00 UTC');
            $pingId = $this->pingLenaAsMia();
            $this->handlePingSent($pingId, $this->storedDeliverAfterOf($pingId));
            $this->clock->modify(self::SENT_AT);
            $this->handle($this->questionToLenaAsRaj('2027-03-03 22:00:00 UTC'));
            $digestItems = self::getContainer()->get('test.notification_digest_items');
            \assert($digestItems instanceof DigestItems);
            $lena = new UserId(self::VIEWERS['Lena'][0]);
            $digestSentAt = new \DateTimeImmutable('2027-03-03 23:30:00 UTC');

            return [
                $this->lenasRowsCreatedAt(),
                $digestItems->digestOf($lena, new \DateTimeImmutable('2027-03-03 00:00:00 UTC'), $digestSentAt),
                $digestItems->digestOf($lena, $digestSentAt, new \DateTimeImmutable('2027-03-04 09:30:00 UTC')),
            ];
        });

        self::assertSame(['2027-03-03 16:00:00+00', self::QUIET_HOURS_END], $rowCreatedAt);
        self::assertSame([['Mia', 'ping', '2027-03-03 16:00:00+00']], self::itemsOf($digestDuringTheHold));
        self::assertSame([['Raj', 'question', self::QUIET_HOURS_END]], self::itemsOf($nextDigest));
    }

    #[Test]
    public function withQuietHoursOffAPingAtTheSameHourReachesHerBellAtOnce(): void
    {
        [$storedDeliverAfter, $rowDeliverAfter, $bellAtOnce] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpLena(isQuietHoursOn: false);
            $pingId = $this->pingLenaAsMia();
            $storedDeliverAfter = $this->storedDeliverAfterOf($pingId);
            $this->handlePingSent($pingId, $storedDeliverAfter);

            return [$storedDeliverAfter, $this->lenasRowsDeliverAfter(), $this->lenasBell()];
        });

        self::assertNull($storedDeliverAfter);
        self::assertSame([null], $rowDeliverAfter);
        self::assertSame([1, [['Mia', 'ping', self::PING_BODY]]], $bellAtOnce);
    }

    #[Test]
    public function aHeldPingLenaClearsBeforeItsEventIsHandledWritesNoBellRow(): void
    {
        [$clearedCount, $rowDeliverAfter] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpLena(isQuietHoursOn: true);
            $pingId = $this->pingLenaAsMia();
            $storedDeliverAfter = $this->storedDeliverAfterOf($pingId);
            $pingClearing = self::getContainer()->get('test.shared_kernel_ping_clearing');
            \assert($pingClearing instanceof PingClearing);
            $clearedCount = $pingClearing->clearForActor(
                new OrganizationId(self::ACME_FOODS),
                new TicketId(self::teamOnlyTicketId()),
                new UserId(self::VIEWERS['Lena'][0]),
                new \DateTimeImmutable('2027-03-03 21:30:00 UTC'),
            );
            $this->handlePingSent($pingId, $storedDeliverAfter);

            return [$clearedCount, $this->lenasRowsDeliverAfter()];
        });

        self::assertSame(1, $clearedCount);
        self::assertSame([], $rowDeliverAfter);
    }

    /**
     * Lena in Europe/Berlin and the organization's quiet hours switch, before anything reads either: Organization's
     * settings reads are cached for the rest of the request.
     */
    private function setUpLena(bool $isQuietHoursOn): void
    {
        self::assertSame(1, self::connection()->executeStatement("UPDATE idn_user SET time_zone = 'Europe/Berlin' WHERE id = ?", [self::VIEWERS['Lena'][0]]));
        self::assertSame(1, self::connection()->executeStatement(
            "UPDATE org_settings SET data = data || jsonb_build_object('quiet_hours', ?::boolean) WHERE organization_id = ?",
            [$isQuietHoursOn ? 'true' : 'false', self::ACME_FOODS],
        ));
    }

    private function pingLenaAsMia(): string
    {
        $ticketKey = self::connection()->fetchOne('SELECT key FROM tkt_ticket WHERE organization_id = ? AND id = ?', [self::ACME_FOODS, self::teamOnlyTicketId()]);
        self::assertIsString($ticketKey);
        $pingId = self::getContainer()->get(PingCommands::class)->ping(
            new OrganizationId(self::ACME_FOODS),
            $ticketKey,
            new UserId(self::VIEWERS['Mia'][0]),
            new UserId(self::VIEWERS['Lena'][0]),
            self::PING_BODY,
            $this->clock->now(),
        )->pingId;
        // The send request ends before worker-notify or a clearing request loads the ping into an entity manager of its own.
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();

        return $pingId;
    }

    private function storedDeliverAfterOf(string $pingId): ?string
    {
        $deliverAfter = self::connection()->fetchOne(
            "SELECT to_char(deliver_after AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS\"+00\"') FROM ntf_ping WHERE organization_id = ? AND id = ?",
            [self::ACME_FOODS, $pingId],
        );
        self::assertTrue(null === $deliverAfter || \is_string($deliverAfter));

        return $deliverAfter;
    }

    /**
     * The PingSent SendPing publishes, delivered at the time stored on the ping, or now when none is.
     */
    private function handlePingSent(string $pingId, ?string $storedDeliverAfter): void
    {
        $this->handle(new PingSent(
            self::ACME_FOODS,
            self::teamOnlyTicketId(),
            $pingId,
            self::VIEWERS['Mia'][0],
            self::VIEWERS['Lena'][0],
            self::VIEWERS['Mia'][0],
            null === $storedDeliverAfter ? $this->clock->now() : new \DateTimeImmutable($storedDeliverAfter),
        ));
    }

    private function handle(PingSent|QuestionAsked $event): void
    {
        $messageBeingHandled = self::getContainer()->get(MessageBeingHandled::class);
        $messageBeingHandled->set(Uuid::v7()->toRfc4122());
        try {
            self::getContainer()->get(NotifyRecipients::class)($event);
        } finally {
            $messageBeingHandled->reset();
        }
    }

    /**
     * @return list<?string> the UTC deliver_after of each of Lena's notification rows, held or not
     */
    private function lenasRowsDeliverAfter(): array
    {
        $rows = self::connection()->fetchFirstColumn(
            "SELECT to_char(deliver_after AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS\"+00\"') FROM ntf_notification WHERE organization_id = ? AND user_id = ? ORDER BY created_at, id",
            [self::ACME_FOODS, self::VIEWERS['Lena'][0]],
        );

        return array_map(static function (mixed $deliverAfter): ?string {
            self::assertTrue(null === $deliverAfter || \is_string($deliverAfter));

            return $deliverAfter;
        }, $rows);
    }

    /**
     * The QuestionAsked of a question it stores open, as Conversation stores it before publishing the event.
     */
    private function questionToLenaAsRaj(string $askedAt): QuestionAsked
    {
        $questionId = Uuid::v7()->toRfc4122();
        self::addOpenQuestion(self::teamOnlyTicketId(), $questionId, 'Lena', 'Raj', $askedAt);

        return new QuestionAsked(
            self::ACME_FOODS,
            self::teamOnlyTicketId(),
            $questionId,
            self::VIEWERS['Lena'][0],
            self::VIEWERS['Raj'][0],
            new \DateTimeImmutable($askedAt),
        );
    }

    /**
     * @return list<string> the UTC created_at of each of Lena's notification rows, oldest first
     */
    private function lenasRowsCreatedAt(): array
    {
        $rows = self::connection()->fetchFirstColumn(
            "SELECT to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS\"+00\"') FROM ntf_notification WHERE organization_id = ? AND user_id = ? ORDER BY created_at, id",
            [self::ACME_FOODS, self::VIEWERS['Lena'][0]],
        );

        return array_map(static function (mixed $createdAt): string {
            self::assertIsString($createdAt);

            return $createdAt;
        }, $rows);
    }

    /**
     * @return list<array{string, string, string}> each listed item's sender, kind and UTC creation time
     */
    private static function itemsOf(Digest $digest): array
    {
        return array_map(
            static fn (DigestItem $item): array => [$item->actorName, $item->kind->value, $item->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').'+00'],
            $digest->items,
        );
    }

    /**
     * @return array{int, list<array{string, string, ?string}>} the open count, and each group's sender, newest kind and
     *                                                          newest ping text
     */
    private function lenasBell(): array
    {
        $bell = self::getContainer()->get(NotificationQueries::class)->bell(new UserId(self::VIEWERS['Lena'][0]));

        return [$bell->openCount, array_map(static fn (BellGroup $group): array => [$group->senderName, $group->newestKind, $group->newestPingText], $bell->groups)];
    }
}
